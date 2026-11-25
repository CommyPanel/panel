<?php

namespace App\Deploy\Stacks;

use App\Enums\Stack;

class DotnetStack implements StackDefinition
{
    private const MIN_MAJOR = 6;

    public function stack(): Stack
    {
        return Stack::Dotnet;
    }

    public function matches(string $path): bool
    {
        return SourceTree::find($path, 'csproj') !== [] || SourceTree::find($path, 'sln', 0) !== [];
    }

    public function plan(string $path, int $port, array $options = []): BuildPlan
    {
        $projects = $this->webProjects($path);

        if ($projects === []) {
            throw new DetectionFailed('Found .NET projects, but none is a web project (SDK "Microsoft.NET.Sdk.Web"). Add a Dockerfile to deploy other kinds of apps.');
        }

        if (count($projects) > 1) {
            throw new DetectionFailed('Found several .NET web projects ('.implode(', ', array_keys($projects)).'). Add a Dockerfile to choose which one to run.');
        }

        $file = array_key_first($projects);
        $meta = $projects[$file];

        if ($meta['major'] === null || $meta['major'] < self::MIN_MAJOR) {
            throw new DetectionFailed("{$file} must target .NET ".self::MIN_MAJOR.' or newer (TargetFramework like net8.0).');
        }

        $major = $meta['major'];
        $version = $major.'.0';
        $assembly = $meta['assembly'];
        SourceTree::safePath($file);
        SourceTree::safePath($assembly);

        // .NET 8+ images ship a non-root "app" user; older images do not.
        $user = $major >= 8 ? "USER \$APP_UID\n" : '';

        $dockerfile = <<<DOCKER
        FROM mcr.microsoft.com/dotnet/sdk:{$version} AS build
        WORKDIR /src
        COPY . .
        RUN dotnet publish "{$file}" -c Release -o /app/publish

        FROM mcr.microsoft.com/dotnet/aspnet:{$version}
        WORKDIR /app
        COPY --from=build /app/publish .
        ENV ASPNETCORE_URLS=http://+:{$port}
        EXPOSE {$port}
        {$user}ENTRYPOINT ["dotnet", "{$assembly}.dll"]

        DOCKER;

        $notes = ["Detected .NET {$version} web project {$file}."];

        if ($major < 8) {
            $notes[] = 'This .NET version has no non-root image user; the app runs as root inside its container.';
        }

        return new BuildPlan(Stack::Dotnet, $dockerfile, $this->dockerignore(), $notes);
    }

    /**
     * @return array<string, array{major: int|null, assembly: string}>
     */
    private function webProjects(string $path): array
    {
        $result = [];

        foreach (SourceTree::find($path, 'csproj') as $file) {
            $xml = $this->parse((string) SourceTree::read($path, $file));

            if ($xml === null || ! str_contains((string) ($xml['Sdk'] ?? ''), 'Microsoft.NET.Sdk.Web')) {
                continue;
            }

            $framework = (string) ($xml->xpath('//TargetFramework')[0] ?? $xml->xpath('//TargetFrameworks')[0] ?? '');
            preg_match('/^net(\d+)\.\d+/', trim(explode(';', $framework)[0]), $match);

            $result[$file] = [
                'major' => isset($match[1]) ? (int) $match[1] : null,
                'assembly' => trim((string) ($xml->xpath('//AssemblyName')[0] ?? '')) ?: basename($file, '.csproj'),
            ];
        }

        return $result;
    }

    private function parse(string $contents): ?\SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($contents, options: LIBXML_NONET);
        libxml_use_internal_errors($previous);

        return $xml === false ? null : $xml;
    }

    private function dockerignore(): string
    {
        return "**/bin\n**/obj\n.git\n.vs\n.env*\n";
    }
}
