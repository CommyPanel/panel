<?php

namespace App\Deploy\Source;

use App\GitHub\GitHubApp;
use App\GitHub\GitHubFailed;
use App\Models\App;
use InvalidArgumentException;

/**
 * How git proves it may read a private repository: a deploy key (SSH) or an
 * access token (HTTPS). Never logged and never put in a URL.
 */
final readonly class GitCredentials
{
    private const USERNAME = '/^[A-Za-z0-9._@+-]{1,100}$/';

    private const TOKEN = '/^[\x21-\x7e]{8,500}$/';

    private function __construct(
        public ?string $privateKey = null,
        public ?string $username = null,
        public ?string $token = null,
    ) {}

    public static function key(string $privateKey): self
    {
        return new self(privateKey: $privateKey);
    }

    /**
     * @throws InvalidArgumentException when the username or token could not be sent safely
     */
    public static function token(string $username, string $token): self
    {
        if (! self::isValidUsername($username) || ! self::isValidToken($token)) {
            throw new InvalidArgumentException('Invalid token credentials.');
        }

        return new self(username: $username, token: $token);
    }

    public static function isValidUsername(string $username): bool
    {
        return preg_match(self::USERNAME, $username) === 1;
    }

    public static function isValidToken(string $token): bool
    {
        return preg_match(self::TOKEN, $token) === 1;
    }

    /**
     * What an app's deployments use: for an app read through the panel's GitHub App, a fresh
     * token for that one repository; otherwise its access token, which wins over its deploy key.
     *
     * @throws GitHubFailed when the GitHub App cannot give access
     */
    public static function forApp(App $app): ?self
    {
        if ($app->github_repo !== null) {
            return self::token('x-access-token', app(GitHubApp::class)->installationToken($app->github_repo));
        }

        if ($app->git_token !== null && $app->git_token_username !== null) {
            return self::token($app->git_token_username, $app->git_token);
        }

        return $app->deploy_key_private === null ? null : self::key($app->deploy_key_private);
    }

    public function isToken(): bool
    {
        return $this->token !== null;
    }

    /**
     * The address git should use with these credentials: HTTPS for a token,
     * SSH for a deploy key. Null when it cannot be derived.
     */
    public function remote(string $url): ?string
    {
        return $this->isToken() ? GitUrl::toHttps($url) : GitUrl::toSsh($url);
    }
}
