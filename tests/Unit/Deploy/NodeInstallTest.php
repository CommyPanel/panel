<?php

use App\Deploy\Stacks\NodeStack;

require_once __DIR__.'/SourceHelpers.php';

function nodePlan(array $files)
{
    return (new NodeStack)->plan(sourceTree(['package.json' => json_encode(['scripts' => ['start' => 'node index.js']]), ...$files]), 3000);
}

it('copies pnpm\'s workspace file, npmrc and hooks next to the lock file, before the code', function () {
    $dockerfile = nodePlan([
        'pnpm-lock.yaml' => 'lockfileVersion: 9',
        'pnpm-workspace.yaml' => "overrides:\n  ansi-styles: 4.2.1\n",
        '.npmrc' => 'registry=https://registry.npmjs.org/',
        '.pnpmfile.cjs' => 'module.exports = {};',
        'patches/a.patch' => 'diff',
    ])->dockerfile;

    expect($dockerfile)->toContain("RUN corepack enable\nCOPY package.json pnpm-lock.yaml pnpm-workspace.yaml .npmrc .pnpmfile.cjs ./\nCOPY patches ./patches\nRUN pnpm install --frozen-lockfile\nCOPY . .\n");
});

it('copies .npmrc for npm, and only what exists', function () {
    expect(nodePlan(['package-lock.json' => '{}', '.npmrc' => 'x=y'])->dockerfile)->toContain("COPY package.json package-lock.json .npmrc ./\nRUN npm ci\n")
        ->and(nodePlan([])->dockerfile)->toContain("COPY package.json ./\nRUN npm install\n");
});

it('copies Yarn\'s settings, releases and plugins', function () {
    $dockerfile = nodePlan(['yarn.lock' => '# yarn', '.yarnrc.yml' => 'nodeLinker: node-modules', '.yarn/releases/yarn-4.0.0.cjs' => '//', '.yarn/plugins/p.cjs' => '//'])->dockerfile;

    expect($dockerfile)->toContain("COPY package.json yarn.lock .yarnrc.yml ./\nCOPY .yarn/releases ./.yarn/releases\nCOPY .yarn/plugins ./.yarn/plugins\nRUN yarn install --immutable");
});
