<?php

namespace App\Deploy\Source;

enum GitProvider: string
{
    case GitHub = 'github';
    case GitLab = 'gitlab';
    case Bitbucket = 'bitbucket';
    case Other = 'other';

    public static function fromUrl(string $url): self
    {
        return match (GitUrl::host($url)) {
            'github.com' => self::GitHub,
            'gitlab.com' => self::GitLab,
            'bitbucket.org' => self::Bitbucket,
            default => self::Other,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::GitHub => 'GitHub',
            self::GitLab => 'GitLab',
            self::Bitbucket => 'Bitbucket',
            self::Other => 'your Git server',
        };
    }

    /**
     * The username this provider expects next to an access token, or null
     * when the user has to name the account the token belongs to.
     */
    public function tokenUsername(): ?string
    {
        return match ($this) {
            self::GitHub => 'x-access-token',
            self::GitLab => 'oauth2',
            self::Bitbucket => 'x-token-auth',
            self::Other => null,
        };
    }

    /**
     * Where to create a read-only access token and what to choose, for this provider.
     *
     * @return array{url: string|null, steps: list<string>}
     */
    public function tokenInstructions(string $repositoryUrl): array
    {
        $path = GitUrl::path($repositoryUrl);
        $host = GitUrl::host($repositoryUrl);

        return match ($this) {
            self::GitHub => [
                'url' => 'https://github.com/settings/personal-access-tokens/new',
                'steps' => [
                    'Create a fine-grained personal access token on GitHub.',
                    'Under Repository access, choose "Only select repositories" and pick this repository.',
                    'Under Permissions, set Contents to "Read-only". Nothing else is needed.',
                ],
            ],
            self::GitLab => [
                'url' => $path === null ? null : "https://{$host}/{$path}/-/settings/access_tokens",
                'steps' => [
                    'Open the project on GitLab: Settings, then Access tokens, then Add new token.',
                    'Choose the Reporter role and only the read_repository scope.',
                ],
            ],
            self::Bitbucket => [
                'url' => $path === null ? null : "https://bitbucket.org/{$path}/admin/access-tokens",
                'steps' => [
                    'Open the repository on Bitbucket: Repository settings, then Access tokens, then Create.',
                    'Give it only the Repositories: Read permission.',
                ],
            ],
            self::Other => [
                'url' => null,
                'steps' => [
                    'Create a token on your Git server that can only read this repository.',
                    'Enter it below with the username it belongs to.',
                ],
            ],
        };
    }

    /**
     * Where to add the deploy key and what to click, for this provider.
     *
     * @return array{url: string|null, steps: list<string>}
     */
    public function deployKeyInstructions(string $repositoryUrl): array
    {
        $path = GitUrl::path($repositoryUrl);
        $host = GitUrl::host($repositoryUrl);

        return match ($this) {
            self::GitHub => [
                'url' => $path === null ? null : "https://github.com/{$path}/settings/keys/new",
                'steps' => [
                    'Open the repository on GitHub: Settings, then Deploy keys, then Add deploy key.',
                    'Give it any title (for example "CommyPanel") and paste the key above.',
                    'Leave "Allow write access" switched off, then click Add key.',
                ],
            ],
            self::GitLab => [
                'url' => $path === null ? null : "https://{$host}/{$path}/-/settings/repository",
                'steps' => [
                    'Open the project on GitLab: Settings, then Repository, then Deploy keys, then Add new key.',
                    'Give it a title (for example "CommyPanel") and paste the key above.',
                    'Leave "Grant write permissions" switched off, then click Add key.',
                ],
            ],
            self::Bitbucket => [
                'url' => $path === null ? null : "https://bitbucket.org/{$path}/admin/access-keys/",
                'steps' => [
                    'Open the repository on Bitbucket: Repository settings, then Access keys, then Add key.',
                    'Give it a label (for example "CommyPanel") and paste the key above.',
                    'Click Add SSH key. Access keys are read-only.',
                ],
            ],
            self::Other => [
                'url' => null,
                'steps' => [
                    'Add the key above to your repository as a read-only deploy key. The setting is usually under the repository\'s Settings.',
                    'Make sure this server can reach your Git server over SSH.',
                ],
            ],
        };
    }
}
