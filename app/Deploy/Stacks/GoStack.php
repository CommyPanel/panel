<?php

namespace App\Deploy\Stacks;

use App\Enums\Stack;

class GoStack implements StackDefinition
{
    public function stack(): Stack
    {
        return Stack::Go;
    }

    public function matches(string $path): bool
    {
        return SourceTree::has($path, 'go.mod');
    }

    public function plan(string $path, int $port, array $options = []): BuildPlan
    {
        $goMod = (string) SourceTree::read($path, 'go.mod');

        if (! preg_match('/^go\s+(\d+)\.(\d+)/m', $goMod, $version)) {
            throw new DetectionFailed('go.mod has no "go" version directive.');
        }

        $goVersion = "{$version[1]}.{$version[2]}";
        $package = SourceTree::safePath($this->mainPackage($path));

        $dockerfile = <<<DOCKER
        FROM golang:{$goVersion} AS build
        WORKDIR /src
        COPY go.mod go.sum* ./
        RUN go mod download
        COPY . .
        RUN CGO_ENABLED=0 go build -trimpath -o /out/app {$package}

        FROM gcr.io/distroless/static-debian12:nonroot
        COPY --from=build /out/app /app
        ENV PORT={$port}
        EXPOSE {$port}
        ENTRYPOINT ["/app"]

        DOCKER;

        return new BuildPlan(Stack::Go, $dockerfile, ".git\n.env*\n", [
            "Detected Go {$goVersion}, building {$package} without cgo.",
        ]);
    }

    private function mainPackage(string $path): string
    {
        if ($this->isMainPackage($path)) {
            return '.';
        }

        $candidates = [];

        foreach (glob($path.'/cmd/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if ($this->isMainPackage($dir)) {
                $candidates[] = './cmd/'.basename($dir);
            }
        }

        return match (count($candidates)) {
            0 => throw new DetectionFailed('No Go main package found in the repository root or cmd/*. Add a Dockerfile to build it.'),
            1 => $candidates[0],
            default => throw new DetectionFailed('Found several Go programs ('.implode(', ', $candidates).'). Add a Dockerfile to choose which one to run.'),
        };
    }

    private function isMainPackage(string $dir): bool
    {
        foreach (glob($dir.'/*.go') ?: [] as $file) {
            if (str_ends_with($file, '_test.go')) {
                continue;
            }

            if (preg_match('/^package\s+main\b/m', (string) file_get_contents($file))) {
                return true;
            }
        }

        return false;
    }
}
