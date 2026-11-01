<?php

namespace App\Deploy\Source;

final class GitUrl
{
    /**
     * Only network transports are accepted by default. Anything that git
     * could interpret as an option, a helper (ext::) or a local path is refused.
     */
    public static function isValid(string $url, bool $allowLocal = false): bool
    {
        if ($url === '' || strlen($url) > 500 || preg_match('/[\s\x00-\x1f\x7f]/', $url) || str_starts_with($url, '-')) {
            return false;
        }

        return match (true) {
            (bool) preg_match('#^https://[A-Za-z0-9.-]+(:\d+)?/[A-Za-z0-9._~/%+-]+$#', $url) => true,
            (bool) preg_match('#^ssh://([A-Za-z0-9._-]+@)?[A-Za-z0-9.-]+(:\d+)?/[A-Za-z0-9._~/%+-]+$#', $url) => true,
            (bool) preg_match('#^[A-Za-z0-9._-]+@[A-Za-z0-9.-]+:[A-Za-z0-9._~/%+-]+$#', $url) => true,
            $allowLocal => str_starts_with($url, '/') && ! str_contains($url, '..'),
            default => false,
        };
    }

    public static function isValidBranch(string $branch): bool
    {
        return (bool) preg_match('#^[A-Za-z0-9._/-]{1,100}$#', $branch)
            && ! str_starts_with($branch, '-')
            && ! str_contains($branch, '..');
    }

    public static function isSsh(string $url): bool
    {
        return str_starts_with($url, 'ssh://') || preg_match('#^[A-Za-z0-9._-]+@[A-Za-z0-9.-]+:#', $url) === 1;
    }

    /**
     * The host name of a remote URL, or null for local paths.
     */
    public static function host(string $url): ?string
    {
        if (preg_match('#^(?:https|ssh)://(?:[A-Za-z0-9._-]+@)?([A-Za-z0-9.-]+)(?::\d+)?/#', $url, $match) === 1) {
            return strtolower($match[1]);
        }

        if (preg_match('#^[A-Za-z0-9._-]+@([A-Za-z0-9.-]+):#', $url, $match) === 1) {
            return strtolower($match[1]);
        }

        return null;
    }

    /**
     * The repository path without a leading slash or a trailing ".git", e.g. "acme/shop".
     */
    public static function path(string $url): ?string
    {
        if (preg_match('#^(?:https|ssh)://(?:[A-Za-z0-9._-]+@)?[A-Za-z0-9.-]+(?::\d+)?/(.+)$#', $url, $match) !== 1
            && preg_match('#^[A-Za-z0-9._-]+@[A-Za-z0-9.-]+:(.+)$#', $url, $match) !== 1) {
            return null;
        }

        return preg_replace('#(\.git)?/?$#', '', ltrim($match[1], '/'));
    }

    /**
     * The HTTPS form of a repository URL (access tokens only work over HTTPS),
     * or null when it cannot be derived.
     */
    public static function toHttps(string $url): ?string
    {
        if (str_starts_with($url, 'https://')) {
            return $url;
        }

        $host = self::host($url);
        $path = self::path($url);

        return $host !== null && $path !== null ? "https://{$host}/{$path}.git" : null;
    }

    /**
     * The SSH form of a repository URL (deploy keys only work over SSH), or
     * null when it cannot be derived.
     */
    public static function toSsh(string $url): ?string
    {
        if (self::isSsh($url)) {
            return $url;
        }

        $host = self::host($url);
        $path = self::path($url);

        return $host !== null && $path !== null ? "git@{$host}:{$path}.git" : null;
    }
}
