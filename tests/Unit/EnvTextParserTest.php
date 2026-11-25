<?php

use App\Deploy\EnvText;

/**
 * The .env text of a Laravel app: read like a dotenv parser, edited without touching other lines.
 */
describe('reading', function () {
    it('reads bare, quoted and exported values, comments and blank lines', function () {
        $parsed = EnvText::parse("# the app\nAPP_NAME=\"My \\\"Shop\\\"\"\n\nexport APP_ENV=production\nTOKEN='a\$b\\n'\nPLAIN=  spaced  \nEMPTY=\nURL=https://x.test/a#frag\nCOLOR=#fff\nTRAIL=value # a comment\nQUOTED=\"v\" # note\n");

        expect($parsed['errors'])->toBe([])
            ->and($parsed['vars'])->toBe([
                'APP_NAME' => 'My "Shop"',
                'APP_ENV' => 'production',
                'TOKEN' => 'a$b\\n',
                'PLAIN' => 'spaced',
                'EMPTY' => '',
                'URL' => 'https://x.test/a#frag',
                'COLOR' => '#fff',
                'TRAIL' => 'value',
                'QUOTED' => 'v',
            ]);
    });

    it('understands the escapes of double quotes and takes single quotes literally', function () {
        $parsed = EnvText::parse("A=\"line1\\nline2\\ttab\\\\ \\\$HOME \\q\"\nB='no \\n escape \$HOME'\n");

        expect($parsed['vars']['A'])->toBe("line1\nline2\ttab\\ \$HOME \\q")
            ->and($parsed['vars']['B'])->toBe('no \\n escape $HOME');
    });

    it('keeps ${VAR} references as text', function () {
        expect(EnvText::parse("A=\${B}/x\nB=\"\${C}\"\n")['vars'])->toBe(['A' => '${B}/x', 'B' => '${C}']);
    });

    it('reads a quoted value that runs over several lines, and says where each variable is', function () {
        $parsed = EnvText::parse("A=1\nKEY=\"-----BEGIN\nmiddle\n-----END\"\nZ=2\n");

        expect($parsed['errors'])->toBe([])
            ->and($parsed['vars']['KEY'])->toBe("-----BEGIN\nmiddle\n-----END")
            ->and($parsed['spans'])->toBe(['A' => [0, 0], 'KEY' => [1, 3], 'Z' => [4, 4]]);
    });

    it('reads Windows and old Mac line endings', function () {
        expect(EnvText::parse("A=1\r\nB=2\rC=3")['vars'])->toBe(['A' => '1', 'B' => '2', 'C' => '3']);
    });
});

describe('mistakes', function () {
    it('names the line of everything that is not a comment, a blank or KEY=value', function () {
        $errors = EnvText::parse("A=1\nnot valid\n1BAD=x\nA B=c\n=nokey\n")['errors'];

        expect(array_column($errors, 'line'))->toBe([2, 3, 4, 5]);
    });

    it('refuses PORT, a key defined twice, an unclosed quote and text after a quote', function () {
        $parsed = EnvText::parse("PORT=80\nA=1\nA=2\nC=\"x\" y\n");

        expect($parsed['errors'])->toHaveCount(3)
            ->and($parsed['errors'][0])->toBe(['line' => 1, 'message' => 'PORT is set by CommyPanel. Remove this line.'])
            ->and($parsed['errors'][1]['line'])->toBe(3)
            ->and($parsed['errors'][1]['message'])->toContain('line 2')
            ->and($parsed['errors'][2]['line'])->toBe(4);

        $open = EnvText::parse("A=1\nB=\"never closed\nC=3\n");

        expect($open['errors'])->toHaveCount(1)->and($open['errors'][0]['line'])->toBe(2)->and($open['errors'][0]['message'])->toContain('never closed');
    });

    it('refuses a name or a value that is too long', function () {
        $parsed = EnvText::parse(str_repeat('K', 129)."=1\nBIG=".str_repeat('x', 32769)."\n");

        expect(array_column($parsed['errors'], 'line'))->toBe([1, 2]);
    });
});

describe('editing', function () {
    $text = "# header\n\nAPP_NAME=old\n# the key\nexport APP_KEY=k\nMULTI=\"a\nb\"\nLAST=1\n";

    it('replaces one variable and leaves every other line as it was', function () use ($text) {
        expect(EnvText::set($text, 'APP_NAME', 'new value'))->toBe("# header\n\nAPP_NAME='new value'\n# the key\nexport APP_KEY=k\nMULTI=\"a\nb\"\nLAST=1\n");
    });

    it('keeps an export prefix, replaces a multi-line value whole and appends new variables', function () use ($text) {
        $changed = EnvText::set(EnvText::set(EnvText::set($text, 'APP_KEY', 'z'), 'MULTI', 'one line'), 'NEW', 'v');

        expect($changed)->toBe("# header\n\nAPP_NAME=old\n# the key\nexport APP_KEY=z\nMULTI='one line'\nLAST=1\nNEW=v\n");
    });

    it('appends to a text with no final line break, and to an empty one', function () {
        expect(EnvText::set('A=1', 'B', '2'))->toBe("A=1\nB=2\n")->and(EnvText::set('', 'B', '2'))->toBe("B=2\n");
    });

    it('removes the lines of one variable, a multi-line one included, and keeps the comments', function () use ($text) {
        expect(EnvText::remove(EnvText::remove($text, 'MULTI'), 'APP_NAME'))->toBe("# header\n\n# the key\nexport APP_KEY=k\nLAST=1\n")
            ->and(EnvText::remove($text, 'MISSING'))->toBe($text);
    });

    it('writes any value so that reading it gives the same value back', function (string $value) {
        $line = EnvText::set('', 'K', $value);

        expect(EnvText::parse($line)['errors'])->toBe([])->and(EnvText::parse($line)['vars']['K'])->toBe($value);
    })->with([
        'plain' => 'abc-123',
        'empty' => '',
        'spaces' => '  a b  ',
        'hash' => 'a #b',
        'quote' => "it's",
        'double quote' => 'say "hi"',
        'dollar' => 'pa$$word${X}',
        'backslash' => 'C:\\path\\n',
        'newline' => "a\nb",
        'carriage return' => "a\r\nb",
        'tab' => "a\tb",
        'unicode' => 'café ☕',
        'mixed' => "it's \"a\" \$x\\ \n end",
        'base64' => 'base64:abc+/=',
    ]);

    it('renders a set of variables sorted, and reads them back', function () {
        $text = EnvText::render(['B' => 'two words', 'A' => '1']);

        expect($text)->toBe("A=1\nB='two words'\n")->and(EnvText::parse($text)['vars'])->toBe(['A' => '1', 'B' => 'two words'])
            ->and(EnvText::render([]))->toBe('');
    });
});
