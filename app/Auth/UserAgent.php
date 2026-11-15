<?php

namespace App\Auth;

/**
 * A short, human label for a browser's User-Agent ("Firefox on macOS"). Only the common
 * browsers and systems are told apart; anything else is shown as unknown, never guessed.
 */
final class UserAgent
{
    /**
     * @return array{browser: string|null, os: string|null}
     */
    public static function describe(?string $agent): array
    {
        $agent = (string) $agent;

        if ($agent === '') {
            return ['browser' => null, 'os' => null];
        }

        // Order matters: most browsers also say "Chrome" or "Safari".
        $browser = match (true) {
            (bool) preg_match('/\b(Edg|EdgA|EdgiOS)\//', $agent) => 'Edge',
            (bool) preg_match('/\b(OPR|Opera)\//', $agent) => 'Opera',
            (bool) preg_match('/\b(Firefox|FxiOS)\//', $agent) => 'Firefox',
            (bool) preg_match('/\b(Chrome|CriOS)\//', $agent) => 'Chrome',
            (bool) preg_match('/\bSafari\//', $agent) => 'Safari',
            (bool) preg_match('/\b(curl|Wget|python-requests|Go-http-client)\b/i', $agent) => 'Script',
            default => null,
        };

        $os = match (true) {
            (bool) preg_match('/Android/', $agent) => 'Android',
            (bool) preg_match('/iPhone|iPad|iPod/', $agent) => 'iOS',
            (bool) preg_match('/Windows/', $agent) => 'Windows',
            (bool) preg_match('/Mac OS X|Macintosh/', $agent) => 'macOS',
            (bool) preg_match('/CrOS/', $agent) => 'ChromeOS',
            (bool) preg_match('/Linux|X11/', $agent) => 'Linux',
            default => null,
        };

        return ['browser' => $browser, 'os' => $os];
    }
}
