<?php

namespace App\Deploy\Source;

use App\Deploy\DeploymentFailed;
use App\Enums\SourceType;
use App\Models\App;
use Illuminate\Support\Facades\File;

class GitSourceFetcher implements SourceFetcher
{
    private ?string $message = null;

    public function __construct(private readonly GitRunner $git) {}

    public function lastCommitMessage(): ?string
    {
        return $this->message;
    }

    public function fetch(App $app, string $destination): string
    {
        if ($app->source_type !== SourceType::Git) {
            throw new DeploymentFailed('Only Git sources can be deployed at the moment.');
        }

        $this->message = null;
        $url = (string) $app->git_url;

        if (! GitUrl::isValid($url, (bool) config('commypanel.deploy.git_allow_local'))) {
            throw new DeploymentFailed('The Git URL is not valid. Use an https:// or ssh URL.');
        }

        if (! GitUrl::isValidBranch($app->git_branch)) {
            throw new DeploymentFailed('The Git branch name is not valid.');
        }

        $credentials = GitCredentials::forApp($app);

        if ($credentials?->isToken()) {
            $url = $credentials->remote($url) ?? throw new DeploymentFailed('Access tokens work over https://, and this Git address cannot be turned into one.');
        }

        $result = $this->git->run(
            ['clone', '--depth', '1', '--single-branch', '--branch', $app->git_branch, '--', $url, $destination],
            $credentials,
        );

        if ($result->failed()) {
            throw new DeploymentFailed('Could not fetch the repository: '.$this->summarise($result->errorOutput()));
        }

        $sha = $this->git->run(['rev-parse', 'HEAD'], cwd: $destination);
        $subject = $this->git->run(['log', '-1', '--format=%s'], cwd: $destination);
        $this->message = $subject->successful() ? (mb_substr(trim($subject->output()), 0, 255) ?: null) : null;

        // The history is not needed to build and must not end up in an image.
        File::deleteDirectory($destination.'/.git');

        return $sha->successful() ? trim($sha->output()) : 'unknown';
    }

    private function summarise(string $output): string
    {
        $lines = array_filter(array_map('trim', explode("\n", $output)));

        return mb_substr((string) end($lines), 0, 300);
    }
}
