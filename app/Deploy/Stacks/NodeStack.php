<?php

namespace App\Deploy\Stacks;

use App\Enums\Stack;

class NodeStack implements StackDefinition
{
    private const DEFAULT_MAJOR = 24;

    private const MIN_MAJOR = 18;

    public function stack(): Stack
    {
        return Stack::Node;
    }

    public function matches(string $path): bool
    {
        return SourceTree::has($path, 'package.json');
    }

    public function plan(string $path, int $port, array $options = []): BuildPlan
    {
        $package = json_decode((string) SourceTree::read($path, 'package.json'), true);

        if (! is_array($package)) {
            throw new DetectionFailed('package.json is not valid JSON.');
        }

        $major = $this->nodeMajor($path, $package);
        $manager = $this->packageManager($path, $package);
        $command = $this->startCommand($package, $manager);
        $hasBuild = isset($package['scripts']['build']) && is_string($package['scripts']['build']);

        $lockfile = match ($manager) {
            'pnpm' => 'pnpm-lock.yaml',
            'yarn' => 'yarn.lock',
            default => SourceTree::has($path, 'package-lock.json') ? 'package-lock.json' : '',
        };
        $berry = $manager === 'yarn' && SourceTree::has($path, '.yarnrc.yml');

        $setup = match ($manager) {
            'pnpm' => "RUN corepack enable\n",
            'yarn' => "RUN corepack enable\n",
            default => '',
        };
        $install = match (true) {
            $manager === 'pnpm' => 'pnpm install --frozen-lockfile',
            $berry => 'yarn install --immutable',
            $manager === 'yarn' => 'yarn install --frozen-lockfile',
            $lockfile !== '' => 'npm ci',
            default => 'npm install',
        };
        $build = $hasBuild ? 'RUN '.$manager.($manager === 'npm' ? ' run' : '')." build\n" : '';
        $prune = match (true) {
            $manager === 'pnpm' => "RUN pnpm prune --prod\n",
            $manager === 'npm' => "RUN npm prune --omit=dev\n",
            $manager === 'yarn' && ! $berry => "RUN yarn install --production --frozen-lockfile --ignore-scripts\n",
            default => '',
        };
        $copy = trim('package.json '.$lockfile.($berry ? ' .yarnrc.yml' : ''));
        $cmd = json_encode($command, JSON_UNESCAPED_SLASHES);

        $dockerfile = <<<DOCKER
        FROM node:{$major}-slim AS build
        WORKDIR /app
        {$setup}COPY {$copy} ./
        RUN {$install}
        COPY . .
        {$build}{$prune}
        FROM node:{$major}-slim
        WORKDIR /app
        ENV NODE_ENV=production PORT={$port}
        COPY --from=build --chown=node:node /app .
        USER node
        EXPOSE {$port}
        CMD {$cmd}

        DOCKER;

        return new BuildPlan(Stack::Node, $dockerfile, "node_modules\n.git\n.env*\nnpm-debug.log\n", [
            "Detected Node.js {$major} with {$manager}; start command: ".implode(' ', $command).'.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $package
     */
    private function nodeMajor(string $path, array $package): int
    {
        $spec = $package['engines']['node'] ?? null;
        $spec = is_string($spec) ? $spec : (string) SourceTree::read($path, '.nvmrc');

        if (preg_match('/(\d+)/', $spec, $match)) {
            $major = (int) $match[1];

            if ($major >= self::MIN_MAJOR && $major <= 30) {
                return $major;
            }
        }

        return self::DEFAULT_MAJOR;
    }

    /**
     * @param  array<string, mixed>  $package
     */
    private function packageManager(string $path, array $package): string
    {
        $declared = is_string($package['packageManager'] ?? null) ? explode('@', $package['packageManager'])[0] : '';

        return match (true) {
            in_array($declared, ['npm', 'pnpm', 'yarn'], true) => $declared,
            SourceTree::has($path, 'pnpm-lock.yaml') => 'pnpm',
            SourceTree::has($path, 'yarn.lock') => 'yarn',
            default => 'npm',
        };
    }

    /**
     * @param  array<string, mixed>  $package
     * @return list<string>
     */
    private function startCommand(array $package, string $manager): array
    {
        if (isset($package['scripts']['start']) && is_string($package['scripts']['start'])) {
            return [$manager, 'start'];
        }

        if (isset($package['main']) && is_string($package['main'])) {
            return ['node', SourceTree::safePath($package['main'])];
        }

        throw new DetectionFailed('package.json has no "start" script or "main" file, so there is nothing to run. Add a start script or a Dockerfile.');
    }
}
