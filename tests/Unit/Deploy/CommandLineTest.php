<?php

use App\Deploy\CommandLine;

it('splits a command into arguments with shell-style quoting', function (string $line, array $args) {
    expect(CommandLine::split($line))->toBe($args);
})->with([
    'plain' => ['php artisan migrate --force', ['php', 'artisan', 'migrate', '--force']],
    'extra spaces' => ["  npm   run\tmigrate ", ['npm', 'run', 'migrate']],
    'single quotes' => ["sh -c 'npm run migrate && npm run seed'", ['sh', '-c', 'npm run migrate && npm run seed']],
    'double quotes' => ['echo "say \"hi\" \\\\ $HOME"', ['echo', 'say "hi" \\ $HOME']],
    'joined quotes' => ["--name='a b'\"c\"", ['--name=a bc']],
    'empty argument' => ["run ''", ['run', '']],
    'backslash escape' => ['touch a\\ b', ['touch', 'a b']],
    'nothing' => ['   ', []],
]);

it('refuses what only a shell would understand', function (string $line, string $message) {
    expect(fn () => CommandLine::split($line))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'and' => ['npm run migrate && npm run seed', "sh -c 'first && second'"],
    'pipe' => ['cat x | grep y', 'without a shell'],
    'semicolon' => ['a; b', 'without a shell'],
    'redirect' => ['a > out', 'without a shell'],
    'variable' => ['echo $HOME', 'without a shell'],
    'subshell' => ['echo `id`', 'without a shell'],
    'open quote' => ["sh -c 'oops", 'quote is not closed'],
    'trailing backslash' => ['run \\', 'ends with a backslash'],
    'newline' => ["a\nb", 'on one line'],
]);
