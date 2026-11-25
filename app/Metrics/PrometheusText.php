<?php

namespace App\Metrics;

/**
 * Builds the Prometheus text format (version 0.0.4): a HELP and a TYPE line for each metric, then one
 * line per series. Everything here is a gauge. Label values are escaped as the format asks (backslash,
 * double quote, line break), so an app or server name can never break out of its label.
 */
final class PrometheusText
{
    /** @var array<string, array{help: string, samples: list<string>}> */
    private array $families = [];

    /**
     * @param  array<string, string>  $labels
     */
    public function gauge(string $name, string $help, int|float $value, array $labels = []): void
    {
        $this->families[$name] ??= ['help' => $help, 'samples' => []];

        $series = '';

        if ($labels !== []) {
            $pairs = [];

            foreach ($labels as $key => $label) {
                $pairs[] = $key.'="'.self::escape($label).'"';
            }

            $series = '{'.implode(',', $pairs).'}';
        }

        $this->families[$name]['samples'][] = $name.$series.' '.self::number($value);
    }

    public function render(): string
    {
        $out = '';

        foreach ($this->families as $name => $family) {
            $out .= "# HELP {$name} ".str_replace(['\\', "\n"], ['\\\\', '\\n'], $family['help'])."\n# TYPE {$name} gauge\n";
            $out .= implode("\n", $family['samples'])."\n";
        }

        return $out;
    }

    public static function escape(string $value): string
    {
        return str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $value);
    }

    private static function number(int|float $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        if (! is_finite($value)) {
            return '0';
        }

        // Plain decimals, never an exponent or a locale comma.
        return rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.') ?: '0';
    }
}
