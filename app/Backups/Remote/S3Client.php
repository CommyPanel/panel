<?php

namespace App\Backups\Remote;

use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\StreamInterface;

/**
 * The few S3 calls the backup copy needs, against any S3-compatible service,
 * with path-style addresses (https://endpoint/bucket/key), which all of them
 * accept. Redirects are never followed, so a service cannot send the
 * credentials elsewhere.
 */
class S3Client
{
    /** A single upload is limited to 5 GiB by S3. */
    public const MAX_UPLOAD = 5 * 1024 ** 3;

    private readonly SigV4 $signer;

    public function __construct(
        private readonly string $endpoint,
        private readonly string $region,
        private readonly string $bucket,
        string $accessKey,
        #[\SensitiveParameter] string $secretKey,
    ) {
        $this->signer = new SigV4($accessKey, $secretKey, $region);
    }

    /**
     * @throws RemoteBackupFailed
     */
    public function put(string $key, string $file): void
    {
        $size = (int) filesize($file);

        if ($size > self::MAX_UPLOAD) {
            throw new RemoteBackupFailed('The file is bigger than the 5 GB a single upload can hold.');
        }

        $hash = (string) hash_file('sha256', $file);
        $stream = fopen($file, 'rb');

        if ($stream === false) {
            throw new RemoteBackupFailed('The file could not be read.');
        }

        try {
            $this->check($this->send('PUT', $key, [], $hash, fn (PendingRequest $r) => $r
                ->withBody(Utils::streamFor($stream), 'application/gzip')
                ->timeout(3600)), 'upload');
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * @throws RemoteBackupFailed
     */
    public function delete(string $key): void
    {
        $response = $this->send('DELETE', $key, [], hash('sha256', ''));

        if ($response->status() !== 404) {
            $this->check($response, 'delete');
        }
    }

    /**
     * Every object below a prefix, as key => time it was last changed.
     *
     * @return array<string, int>
     *
     * @throws RemoteBackupFailed
     */
    public function list(string $prefix): array
    {
        $out = [];
        $token = null;

        do {
            $query = ['list-type' => '2', 'prefix' => $prefix] + ($token === null ? [] : ['continuation-token' => $token]);
            $response = $this->send('GET', '', $query, hash('sha256', ''));
            $this->check($response, 'list');

            $xml = @simplexml_load_string($response->body());

            if ($xml === false) {
                throw new RemoteBackupFailed('The storage answered with something that is not a list.');
            }

            foreach ($xml->Contents ?? [] as $item) {
                $out[(string) $item->Key] = (int) strtotime((string) $item->LastModified);
            }

            $token = ((string) $xml->IsTruncated) === 'true' ? (string) $xml->NextContinuationToken : null;
        } while ($token !== null && $token !== '');

        return $out;
    }

    /**
     * Objects below a prefix with their sizes, newest first, at most `$limit` of them
     * (the second value says there were more).
     *
     * @return array{0: list<array{key: string, size: int, time: int}>, 1: bool}
     *
     * @throws RemoteBackupFailed
     */
    public function listDetailed(string $prefix, int $limit = 500): array
    {
        $out = [];
        $token = null;
        $pages = 0;

        do {
            $query = ['list-type' => '2', 'prefix' => $prefix] + ($token === null ? [] : ['continuation-token' => $token]);
            $response = $this->send('GET', '', $query, hash('sha256', ''));
            $this->check($response, 'list');

            $xml = @simplexml_load_string($response->body());

            if ($xml === false) {
                throw new RemoteBackupFailed('The storage answered with something that is not a list.');
            }

            foreach ($xml->Contents ?? [] as $item) {
                $out[] = ['key' => (string) $item->Key, 'size' => (int) $item->Size, 'time' => (int) strtotime((string) $item->LastModified)];
            }

            $token = ((string) $xml->IsTruncated) === 'true' ? (string) $xml->NextContinuationToken : null;
        } while ($token !== null && $token !== '' && ++$pages < 20);

        usort($out, fn (array $a, array $b) => [$b['time'], $a['key']] <=> [$a['time'], $b['key']]);

        return [array_slice($out, 0, $limit), count($out) > $limit || ($token !== null && $token !== '')];
    }

    /**
     * Open one object for reading: its size (0 when unknown) and the body as a stream.
     *
     * @return array{size: int, stream: StreamInterface}
     *
     * @throws RemoteBackupFailed
     */
    public function open(string $key): array
    {
        $response = $this->send('GET', $key, [], hash('sha256', ''), fn (PendingRequest $r) => $r->withOptions(['stream' => true])->timeout(3600));
        $this->check($response, 'download');

        return ['size' => (int) $response->header('Content-Length'), 'stream' => $response->toPsrResponse()->getBody()];
    }

    /**
     * Whether the settings work: a small object is written and removed again.
     *
     * @throws RemoteBackupFailed
     */
    public function test(string $prefix): void
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'cp-s3-');
        file_put_contents($file, 'CommyPanel connection test');
        $key = trim($prefix, '/').'/.commypanel-test-'.bin2hex(random_bytes(4));
        $key = ltrim($key, '/');

        try {
            $this->put($key, $file);
            $this->delete($key);
        } finally {
            @unlink($file);
        }
    }

    /**
     * @param  array<string, string>  $query
     * @param  (callable(PendingRequest): PendingRequest)|null  $configure
     *
     * @throws RemoteBackupFailed
     */
    private function send(string $method, string $key, array $query, string $payloadHash, ?callable $configure = null): Response
    {
        $parts = parse_url($this->endpoint);
        $host = ($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');
        $path = '/'.$this->bucket.($key === '' ? '/' : '/'.$key);
        $date = gmdate('Ymd\THis\Z');

        $headers = ['Host' => $host, 'x-amz-content-sha256' => $payloadHash, 'x-amz-date' => $date];
        $headers['Authorization'] = $this->signer->authorization($method, $path, $query, $headers, $payloadHash, $date);

        $request = Http::withoutRedirecting()->connectTimeout(15)->timeout(60)->withHeaders($headers);

        if ($configure !== null) {
            $request = $configure($request);
        }

        $url = rtrim($this->endpoint, '/').implode('/', array_map(rawurlencode(...), explode('/', $path)))
            .($query === [] ? '' : '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986));

        try {
            return $request->send($method, $url);
        } catch (ConnectionException $e) {
            throw new RemoteBackupFailed('The storage could not be reached: '.$this->short($e->getMessage()), 0, $e);
        }
    }

    /**
     * @throws RemoteBackupFailed
     */
    private function check(Response $response, string $what): void
    {
        if ($response->successful()) {
            return;
        }

        if ($response->redirect()) {
            throw new RemoteBackupFailed("The storage redirected the {$what} elsewhere, which is not followed. Check the address and the region.");
        }

        $code = '';
        $message = '';

        if (($xml = @simplexml_load_string($response->body())) !== false) {
            $code = (string) ($xml->Code ?? '');
            $message = (string) ($xml->Message ?? '');
        }

        $reason = match ($code) {
            'InvalidAccessKeyId' => 'the access key is not known to the storage',
            'SignatureDoesNotMatch' => 'the secret key is wrong (or the region is)',
            'NoSuchBucket' => 'the bucket does not exist',
            'AccessDenied' => 'the key may not do this in that bucket',
            'AuthorizationHeaderMalformed' => 'the region does not match the bucket',
            default => trim("{$code} {$message}") !== '' ? trim("{$code} {$message}") : 'HTTP '.$response->status(),
        };

        throw new RemoteBackupFailed("The {$what} was refused: {$reason}.");
    }

    private function short(string $text): string
    {
        return mb_substr((string) preg_replace('/\s+/', ' ', $text), 0, 200);
    }
}
