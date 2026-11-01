<?php

namespace App\Deploy\Source;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * Runs git the same way everywhere (access checks and deployments): never
 * prompts for a password, never uses a credential helper or external
 * transports. A deploy key is passed through a short-lived 0600 file; an
 * access token through the environment (readable only by this user, unlike
 * the command line), with redirects off so it is never sent to another host.
 */
class GitRunner
{
    /**
     * @param  list<string>  $args  git arguments after the executable, e.g. ['ls-remote', '--heads', '--', $url]
     */
    public function run(array $args, ?GitCredentials $credentials = null, ?string $cwd = null, ?int $timeout = null): ProcessResult
    {
        $keyFile = null;

        try {
            $process = Process::timeout($timeout ?? (int) config('commypanel.deploy.git_timeout'))
                ->env($this->environment($credentials, $keyFile));

            if ($cwd !== null) {
                $process = $process->path($cwd);
            }

            return $process->run(['git', '-c', 'credential.helper=', '-c', 'protocol.ext.allow=never', ...$args]);
        } finally {
            if ($keyFile !== null) {
                File::delete($keyFile);
            }
        }
    }

    /**
     * @return array<string, string>
     */
    private function environment(?GitCredentials $credentials, ?string &$keyFile): array
    {
        $env = ['GIT_TERMINAL_PROMPT' => '0'];

        if ($credentials?->token !== null) {
            return [
                ...$env,
                'GIT_CONFIG_COUNT' => '2',
                'GIT_CONFIG_KEY_0' => 'http.extraHeader',
                'GIT_CONFIG_VALUE_0' => 'Authorization: Basic '.base64_encode("{$credentials->username}:{$credentials->token}"),
                'GIT_CONFIG_KEY_1' => 'http.followRedirects',
                'GIT_CONFIG_VALUE_1' => 'false',
            ];
        }

        $privateKey = $credentials?->privateKey;

        if ($privateKey === null) {
            return $env;
        }

        $dir = rtrim((string) config('commypanel.data_path'), '/').'/tmp';
        File::ensureDirectoryExists($dir, 0700);

        $keyFile = $dir.'/key-'.bin2hex(random_bytes(8));
        File::put($keyFile, rtrim($privateKey)."\n");
        chmod($keyFile, 0600);

        $env['GIT_SSH_COMMAND'] = sprintf(
            'ssh -i %s -o IdentitiesOnly=yes -o BatchMode=yes -o StrictHostKeyChecking=accept-new -o UserKnownHostsFile=%s',
            escapeshellarg($keyFile),
            escapeshellarg($dir.'/known_hosts'),
        );

        return $env;
    }
}
