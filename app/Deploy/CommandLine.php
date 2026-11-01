<?php

namespace App\Deploy;

use Closure;
use InvalidArgumentException;

/**
 * Splits a command typed by the user into an argument list, with shell-style
 * quoting (single quotes, double quotes, backslash) but no shell: nothing is
 * expanded and nothing is evaluated. Operators such as && or $VAR would only
 * do something in a shell, so they are refused outside quotes with a hint to
 * run `sh -c '...'` inside the container instead.
 */
final class CommandLine
{
    private const OPERATORS = [';', '|', '&', '<', '>', '`', '$'];

    /**
     * A validation rule that accepts anything split() can turn into a command.
     */
    public static function rule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            try {
                if (self::split((string) $value) === []) {
                    $fail('Enter a command, or leave the field empty.');
                }
            } catch (InvalidArgumentException $e) {
                $fail($e->getMessage());
            }
        };
    }

    /**
     * @return list<string>
     *
     * @throws InvalidArgumentException with a message fit to show the user
     */
    public static function split(string $line): array
    {
        $args = [];
        $current = '';
        $inArg = false;
        $quote = null;
        $length = strlen($line);

        for ($i = 0; $i < $length; $i++) {
            $char = $line[$i];

            if ($quote === "'") {
                if ($char === "'") {
                    $quote = null;
                } else {
                    $current .= $char;
                }

                continue;
            }

            if ($quote === '"') {
                if ($char === '"') {
                    $quote = null;
                } elseif ($char === '\\' && $i + 1 < $length && in_array($line[$i + 1], ['"', '\\'], true)) {
                    $current .= $line[++$i];
                } else {
                    $current .= $char;
                }

                continue;
            }

            if ($char === ' ' || $char === "\t") {
                if ($inArg) {
                    $args[] = $current;
                    $current = '';
                    $inArg = false;
                }

                continue;
            }

            if ($char === "\n" || $char === "\r" || $char === "\0") {
                throw new InvalidArgumentException('Write the command on one line.');
            }

            if (in_array($char, self::OPERATORS, true)) {
                throw new InvalidArgumentException("The command runs without a shell, so {$char} has no special meaning. To chain commands or use variables, run them through a shell: sh -c 'first && second'.");
            }

            $inArg = true;

            if ($char === "'" || $char === '"') {
                $quote = $char;
            } elseif ($char === '\\') {
                if ($i + 1 >= $length) {
                    throw new InvalidArgumentException('The command ends with a backslash.');
                }
                $current .= $line[++$i];
            } else {
                $current .= $char;
            }
        }

        if ($quote !== null) {
            throw new InvalidArgumentException("A {$quote} quote is not closed.");
        }

        if ($inArg) {
            $args[] = $current;
        }

        return $args;
    }
}
