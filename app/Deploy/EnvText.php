<?php

namespace App\Deploy;

/**
 * A .env file as text, read the way a dotenv parser reads it and edited without touching the lines
 * that did not change, so comments, blank lines and order survive. It is the Laravel apps' form of
 * their variables (`apps.env_text`); the variables the containers get are derived from it.
 *
 * Lines are blank, `# comments`, or `KEY=value` with an optional `export `. A value is bare (up to
 * a ` #` comment), "double quoted" (escapes \n \r \t \" \\ \$; may span lines) or 'single quoted'
 * (literal; may span lines). `${VAR}` stays as text. PORT is refused (CommyPanel sets it), as is a
 * key defined twice.
 */
final class EnvText
{
    public const MAX_KEY = 128;

    public const MAX_VALUE = 32768;

    /**
     * @return array{vars: array<string, string>, spans: array<string, array{0: int, 1: int}>, errors: list<array{line: int, message: string}>}
     *                                                                                                                                          `spans` are 0-based first and last line of each variable; `errors` have 1-based line numbers
     */
    public static function parse(string $text): array
    {
        $lines = explode("\n", self::normalize($text));
        $vars = [];
        $spans = [];
        $errors = [];
        $count = count($lines);

        for ($i = 0; $i < $count; $i++) {
            $start = $i;
            $trimmed = ltrim($lines[$i]);

            if ($trimmed === '' || $trimmed[0] === '#') {
                continue;
            }

            if (preg_match('/^(?:export[ \t]+)?([A-Za-z_][A-Za-z0-9_]*)[ \t]*=(.*)$/s', $trimmed, $m) !== 1) {
                $errors[] = ['line' => $i + 1, 'message' => 'This is not a comment or a KEY=value line.'];

                continue;
            }

            [$key, $rest] = [$m[1], ltrim($m[2], " \t")];
            $value = null;

            if ($rest !== '' && ($rest[0] === '"' || $rest[0] === "'")) {
                $read = self::readQuoted($lines, $i, $rest);

                if ($read === null) {
                    $errors[] = ['line' => $start + 1, 'message' => "The quote opened for {$key} is never closed."];
                    $i = $count;

                    continue;
                }

                [$value, $after] = $read;
                $after = trim($after);

                if ($after !== '' && $after[0] !== '#') {
                    $errors[] = ['line' => $i + 1, 'message' => "Unexpected text after the closing quote of {$key}."];

                    continue;
                }
            } else {
                $value = rtrim((string) preg_replace('/[ \t]+#.*$/', '', $rest));
            }

            if (strlen($key) > self::MAX_KEY) {
                $errors[] = ['line' => $start + 1, 'message' => 'The name '.substr($key, 0, 20).'… is longer than '.self::MAX_KEY.' characters.'];

                continue;
            }

            if ($key === 'PORT') {
                $errors[] = ['line' => $start + 1, 'message' => 'PORT is set by CommyPanel. Remove this line.'];

                continue;
            }

            if (strlen($value) > self::MAX_VALUE) {
                $errors[] = ['line' => $start + 1, 'message' => "The value of {$key} is longer than ".self::MAX_VALUE.' characters.'];

                continue;
            }

            if (isset($spans[$key])) {
                $errors[] = ['line' => $start + 1, 'message' => "{$key} is already defined on line ".($spans[$key][0] + 1).'.'];

                continue;
            }

            $vars[$key] = $value;
            $spans[$key] = [$start, $i];
        }

        return ['vars' => $vars, 'spans' => $spans, 'errors' => $errors];
    }

    /**
     * The text with one variable set: its lines replaced, or a new line at the end.
     */
    public static function set(string $text, string $key, string $value): string
    {
        $text = self::normalize($text);
        $parsed = self::parse($text);
        $line = $key.'='.self::quote($value);

        if (! isset($parsed['spans'][$key])) {
            return ($text === '' || str_ends_with($text, "\n") ? $text : $text."\n").$line."\n";
        }

        [$first, $last] = $parsed['spans'][$key];
        $lines = explode("\n", $text);

        // Keep an `export ` the person wrote.
        if (preg_match('/^\s*export[ \t]+/', $lines[$first]) === 1) {
            $line = 'export '.$line;
        }

        array_splice($lines, $first, $last - $first + 1, [$line]);

        return implode("\n", $lines);
    }

    /**
     * The text without one variable's lines (comments around it stay).
     */
    public static function remove(string $text, string $key): string
    {
        $text = self::normalize($text);
        $parsed = self::parse($text);

        if (! isset($parsed['spans'][$key])) {
            return $text;
        }

        [$first, $last] = $parsed['spans'][$key];
        $lines = explode("\n", $text);
        array_splice($lines, $first, $last - $first + 1);

        return implode("\n", $lines);
    }

    /**
     * A value as the text of a line: plain when it is safe, single quotes (taken literally) when it
     * has other characters, double quotes with escapes only when it has a quote or a line break.
     */
    public static function quote(string $value): string
    {
        if (preg_match('/^[A-Za-z0-9_@%+=:,.\/-]*$/', $value) === 1) {
            return $value;
        }

        if (! str_contains($value, "'") && ! str_contains($value, "\n") && ! str_contains($value, "\r")) {
            return "'".$value."'";
        }

        return '"'.strtr($value, ['\\' => '\\\\', '"' => '\\"', "\n" => '\\n', "\r" => '\\r', '$' => '\\$']).'"';
    }

    /**
     * A set of variables as text, sorted by name.
     *
     * @param  array<string, string>  $vars
     */
    public static function render(array $vars): string
    {
        ksort($vars);

        $lines = [];

        foreach ($vars as $key => $value) {
            $lines[] = $key.'='.self::quote($value);
        }

        return $lines === [] ? '' : implode("\n", $lines)."\n";
    }

    public static function normalize(string $text): string
    {
        return str_replace(["\r\n", "\r"], "\n", $text);
    }

    /**
     * Reads a quoted value that may run over several lines.
     *
     * @param  list<string>  $lines
     * @return array{0: string, 1: string}|null the value and what follows the closing quote, or null when it never closes
     */
    private static function readQuoted(array $lines, int &$i, string $rest): ?array
    {
        $quote = $rest[0];
        $value = '';
        $current = substr($rest, 1);
        $count = count($lines);

        while (true) {
            $length = strlen($current);

            for ($p = 0; $p < $length; $p++) {
                $c = $current[$p];

                if ($c === $quote) {
                    return [$value, substr($current, $p + 1)];
                }

                if ($quote === '"' && $c === '\\' && $p + 1 < $length) {
                    $next = $current[++$p];
                    $value .= match ($next) {
                        'n' => "\n",
                        'r' => "\r",
                        't' => "\t",
                        '"', '\\', '$' => $next,
                        default => '\\'.$next,
                    };

                    continue;
                }

                $value .= $c;
            }

            if ($i + 1 >= $count) {
                return null;
            }

            $value .= "\n";
            $current = $lines[++$i];
        }
    }
}
