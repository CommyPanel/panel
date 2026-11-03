<?php

namespace App\Dns;

use App\Audit\AuditLogger;
use App\Host\Data\PublicAddresses;
use App\Host\Exceptions\HostOperationFailed;
use App\Host\HostDriver;
use App\Jobs\DetectServerAddressJob;
use App\Models\Setting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * The public IP addresses of this server, which domains must point at.
 *
 * They are found by themselves, in the background, the first time a page needs
 * them: first from the server's own network interfaces (nothing leaves the
 * machine), and only if it has no public address there (behind a NAT) by asking
 * api.ipify.org once. A person can correct them anywhere they are shown, and
 * what a person set is never replaced by a detection.
 */
class ServerAddress
{
    private const V4 = 'server.ipv4';

    private const V6 = 'server.ipv6';

    private const SOURCE = 'server.source';

    private const MARKER = 'commypanel.server-address.detection';

    /** How long a detection may run before it counts as lost, and how long a failure is not retried. */
    private const RUNNING_SECONDS = 120;

    private const RETRY_SECONDS = 600;

    public function __construct(private readonly HostDriver $host) {}

    public function ipv4(): ?string
    {
        return Setting::read(self::V4) ?: null;
    }

    public function ipv6(): ?string
    {
        return Setting::read(self::V6) ?: null;
    }

    public function isConfigured(): bool
    {
        return $this->ipv4() !== null || $this->ipv6() !== null;
    }

    /**
     * 'auto' when it was found by itself, 'manual' when a person set it, null when there is none.
     */
    public function source(): ?string
    {
        if (! $this->isConfigured()) {
            return null;
        }

        return Setting::read(self::SOURCE) === 'auto' ? 'auto' : 'manual';
    }

    public function save(?string $ipv4, ?string $ipv6, string $source = 'manual'): void
    {
        Setting::write(self::V4, $ipv4 === '' ? null : $ipv4);
        Setting::write(self::V6, $ipv6 === '' ? null : $ipv6);
        Setting::write(self::SOURCE, $source);
        Cache::forget(self::MARKER);
    }

    /**
     * What a page shows. Starts the detection in the background if there is no
     * address yet and none was tried lately, so a page never waits for it.
     *
     * @return array{ipv4: string|null, ipv6: string|null, source: string|null, detecting: bool, failed: bool}
     */
    public function forPage(): array
    {
        $this->ensureDetected();

        $marker = $this->marker();
        $configured = $this->isConfigured();

        return [
            'ipv4' => $this->ipv4(),
            'ipv6' => $this->ipv6(),
            'source' => $this->source(),
            'detecting' => ! $configured && $marker['status'] === 'running',
            'failed' => ! $configured && $marker['status'] === 'failed',
        ];
    }

    /**
     * Queue a detection when there is no address and nothing was tried lately.
     */
    public function ensureDetected(): void
    {
        if ($this->isConfigured() || $this->marker()['status'] !== 'none') {
            return;
        }

        Cache::put(self::MARKER, ['status' => 'running', 'at' => now()->getTimestamp()], self::RETRY_SECONDS + self::RUNNING_SECONDS);
        DetectServerAddressJob::dispatch();
    }

    /**
     * Find the address and keep it, unless there already is one. Run by the job.
     */
    public function runDetection(): void
    {
        if ($this->isConfigured()) {
            Cache::forget(self::MARKER);

            return;
        }

        $found = $this->detect();

        if ($found['ipv4'] === null && $found['ipv6'] === null) {
            Cache::put(self::MARKER, ['status' => 'failed', 'at' => now()->getTimestamp()], self::RETRY_SECONDS);

            return;
        }

        $this->save($found['ipv4'], $found['ipv6'], 'auto');
        app(AuditLogger::class)->record('server.address_detected', null, $found);
    }

    /**
     * The addresses this server can be reached at. The server's own interfaces are
     * asked first; only a server that has no public address there is looked up by an
     * outside service, and only if that is switched on.
     *
     * @return array{ipv4: string|null, ipv6: string|null, via: string|null}
     */
    public function detect(): array
    {
        try {
            $local = $this->host->publicAddresses();
            $agentAnswered = true;
        } catch (HostOperationFailed) {
            $local = new PublicAddresses;
            $agentAnswered = false;
        }

        $v4 = $local->primaryIpv4 ?? ($local->ipv4[0] ?? null);
        $v6 = $local->primaryIpv6 ?? ($local->ipv6[0] ?? null);

        if ($v4 !== null || $v6 !== null) {
            return ['ipv4' => $v4, 'ipv6' => $v6, 'via' => 'interface'];
        }

        // Behind a NAT: the public address is only known to the provider's router.
        // Without an answer from the agent nothing is known about the interfaces, so nothing is looked up either.
        if ($agentAnswered && config('commypanel.address_lookup')) {
            $v4 = $this->lookup('https://api.ipify.org', FILTER_FLAG_IPV4);
            $v6 = $this->lookup('https://api6.ipify.org', FILTER_FLAG_IPV6);

            if ($v4 !== null || $v6 !== null) {
                return ['ipv4' => $v4, 'ipv6' => $v6, 'via' => 'lookup'];
            }
        }

        return ['ipv4' => null, 'ipv6' => null, 'via' => null];
    }

    private function lookup(string $url, int $family): ?string
    {
        try {
            $ip = trim(Http::timeout(5)->withoutRedirecting()->get($url)->body());
        } catch (ConnectionException) {
            return null;
        }

        return filter_var($ip, FILTER_VALIDATE_IP, $family) === false ? null : $ip;
    }

    /**
     * @return array{status: 'none'|'running'|'failed', at: int}
     */
    private function marker(): array
    {
        $marker = Cache::get(self::MARKER);

        if (! is_array($marker) || ! in_array($marker['status'] ?? null, ['running', 'failed'], true)) {
            return ['status' => 'none', 'at' => 0];
        }

        $status = $marker['status'] === 'failed' ? 'failed' : 'running';
        $at = (int) ($marker['at'] ?? 0);

        // A detection that never reported back (the queue was down) is tried again.
        if ($status === 'running' && now()->getTimestamp() - $at > self::RUNNING_SECONDS) {
            return ['status' => 'none', 'at' => 0];
        }

        return ['status' => $status, 'at' => $at];
    }
}
