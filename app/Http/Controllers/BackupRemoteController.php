<?php

namespace App\Http\Controllers;

use App\Audit\AuditLogger;
use App\Backups\Remote\RemoteBackupFailed;
use App\Backups\Remote\RemoteSettings;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Where backups are copied to off this server (any S3-compatible storage). The
 * secret key is write-only: it is stored encrypted and never shown again.
 */
class BackupRemoteController extends Controller
{
    public function update(Request $request, RemoteSettings $settings, AuditLogger $audit): RedirectResponse
    {
        $data = $this->validated($request, $settings, secretRequired: ! $settings->toArray()['has_secret']);
        $settings->save($data);

        $audit->record('backup.remote_changed', $request->user(), [
            'enabled' => (bool) $data['enabled'],
            'endpoint' => $data['endpoint'],
            'bucket' => $data['bucket'],
            'prefix' => $data['prefix'] ?? '',
            'keep' => (int) $data['keep'],
            'secret_changed' => ($data['secret_key'] ?? '') !== '',
        ]);

        return back()->with('status', $data['enabled']
            ? 'Saved. Backups made from now on are copied there as well.'
            : 'Saved. Copying to the storage is off.');
    }

    /**
     * Try the settings that are in the form, saved or not, by writing and deleting a small file.
     */
    public function test(Request $request, RemoteSettings $settings): RedirectResponse
    {
        $data = $this->validated($request, $settings, secretRequired: ! $settings->toArray()['has_secret']);

        try {
            $settings->clientFor($data)->test((string) ($data['prefix'] ?? ''));
        } catch (RemoteBackupFailed $e) {
            return back()->withErrors(['remote' => $e->getMessage()]);
        }

        return back()->with('status', 'The storage accepted a test file, so these settings work.');
    }

    public function destroy(Request $request, RemoteSettings $settings, AuditLogger $audit): RedirectResponse
    {
        $settings->clear();
        $audit->record('backup.remote_removed', $request->user());

        return back()->with('status', 'The storage settings were removed. Copies already there stay where they are.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, RemoteSettings $settings, bool $secretRequired): array
    {
        return $request->validate([
            'enabled' => ['boolean'],
            'endpoint' => ['required', 'string', 'max:200', $this->endpoint(...)],
            'region' => ['nullable', 'string', 'max:40', 'regex:/^[a-z0-9-]+$/'],
            'bucket' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/'],
            'prefix' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9._\/-]*$/'],
            'access_key' => ['required', 'string', 'max:128', 'regex:/^\S+$/'],
            'secret_key' => [$secretRequired ? 'required' : 'nullable', 'string', 'max:256'],
            'keep' => ['required', 'integer', 'min:1', 'max:365'],
        ]);
    }

    /**
     * An https address of a storage service. Not this server itself or its link-local
     * address (where cloud providers keep their metadata), so the panel cannot be
     * pointed at its own insides; a server in the private network is fine.
     */
    private function endpoint(string $attribute, mixed $value, Closure $fail): void
    {
        $parts = parse_url((string) $value);
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));

        if (($parts['scheme'] ?? '') !== 'https' || $host === '' || isset($parts['user']) || isset($parts['query']) || isset($parts['fragment']) || ! in_array($parts['path'] ?? '', ['', '/'], true)) {
            $fail('Use the https address of the storage, such as https://s3.example.com (no path).');

            return;
        }

        $isIp = filter_var($host, FILTER_VALIDATE_IP) !== false;

        if ($host === 'localhost' || str_ends_with($host, '.localhost') || ($isIp && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_RES_RANGE) === false)) {
            $fail('That address points at this server itself or a reserved range, which is not allowed.');
        }
    }
}
