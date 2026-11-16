<?php

namespace App\Deploy\Stacks;

use App\Enums\Stack;

/**
 * Python web apps, built the way most people write them: FastAPI/Starlette (uvicorn), Flask and
 * Django (gunicorn), with dependencies from requirements.txt, Poetry, uv, Pipenv or a plain
 * pyproject.toml. A Procfile `web:` line, when there is one, says how to start it. Otherwise the
 * start command is worked out from the framework and the place the app object is created, and
 * when that is not possible the build asks for a Dockerfile instead of guessing.
 *
 * Everything read from the repository that ends up in the generated Dockerfile is checked first:
 * versions are digits, module names are identifiers, and the Procfile command is JSON-encoded.
 */
class PythonStack implements StackDefinition
{
    /** Newest and oldest minor versions that have official slim images, and what is used when the project says nothing. */
    private const MIN_MINOR = 9;

    private const MAX_MINOR = 14;

    private const DEFAULT_VERSION = '3.12';

    /** Files that usually create the app object, by name. */
    private const APP_FILES = ['main.py', 'app.py', 'wsgi.py', 'asgi.py', 'server.py', 'api.py', 'application.py', '__init__.py'];

    public function stack(): Stack
    {
        return Stack::Python;
    }

    public function matches(string $path): bool
    {
        return SourceTree::has($path, 'pyproject.toml')
            || SourceTree::has($path, 'requirements.txt')
            || SourceTree::has($path, 'Pipfile');
    }

    public function plan(string $path, int $port): BuildPlan
    {
        $version = $this->pythonVersion($path);
        $manager = $this->packageManager($path);
        $dependencies = $this->dependencyText($path);
        [$command, $server, $how] = $this->startCommand($path, $dependencies);
        $extra = $server !== null && ! $this->mentions($dependencies, $server) ? "RUN pip install {$server}\n" : '';

        $install = $this->install($path, $manager);
        $cmd = json_encode(['sh', '-c', 'exec '.$command], JSON_UNESCAPED_SLASHES);

        $dockerfile = <<<DOCKER
        FROM python:{$version} AS build
        ENV PIP_DISABLE_PIP_VERSION_CHECK=1 PIP_NO_CACHE_DIR=1
        RUN python -m venv /opt/venv
        ENV VIRTUAL_ENV=/opt/venv PATH=/opt/venv/bin:\$PATH
        WORKDIR /app
        {$install}{$extra}
        FROM python:{$version}-slim
        RUN useradd --create-home --uid 10001 app
        WORKDIR /app
        ENV PATH=/opt/venv/bin:\$PATH PYTHONUNBUFFERED=1 PYTHONDONTWRITEBYTECODE=1 PORT={$port}
        COPY --from=build /opt/venv /opt/venv
        COPY --from=build --chown=app:app /app /app
        USER app
        EXPOSE {$port}
        CMD {$cmd}

        DOCKER;

        return new BuildPlan(Stack::Python, $dockerfile, ".git\n.env*\n__pycache__\n*.pyc\n.venv\nvenv\n", [
            "Detected Python {$version} with {$manager}; start command: {$how}.",
        ]);
    }

    /**
     * The Python version to use: `.python-version`, then Heroku's `runtime.txt`, then the
     * project's own requirement; a lower bound alone (">=3.10") means the default.
     */
    private function pythonVersion(string $path): string
    {
        $pyproject = (string) SourceTree::read($path, 'pyproject.toml');
        $specs = [
            (string) SourceTree::read($path, '.python-version'),
            (string) SourceTree::read($path, 'runtime.txt'),
        ];

        foreach (['/^\s*requires-python\s*=\s*["\']([^"\']+)["\']/m', '/^\s*python\s*=\s*["\']([^"\']+)["\']/m'] as $pattern) {
            if (preg_match($pattern, $pyproject, $match)) {
                $specs[] = $match[1];
            }
        }

        foreach ($specs as $spec) {
            $spec = trim($spec);

            // ">=3.10" or ">3.9" only sets a floor: the default is a better choice than the oldest.
            if ($spec === '' || preg_match('/^\s*>=?\s*\d/', $spec) === 1) {
                continue;
            }

            if (preg_match('/(?:python-)?(?:[~^=!<]*)\s*3\.(\d{1,2})/', $spec, $match)) {
                $minor = (int) $match[1];

                if ($minor >= self::MIN_MINOR && $minor <= self::MAX_MINOR) {
                    return '3.'.$minor;
                }
            }
        }

        return self::DEFAULT_VERSION;
    }

    private function packageManager(string $path): string
    {
        $pyproject = (string) SourceTree::read($path, 'pyproject.toml');

        return match (true) {
            SourceTree::has($path, 'uv.lock') => 'uv',
            str_contains($pyproject, '[tool.poetry]') => 'Poetry',
            SourceTree::has($path, 'requirements.txt') => 'pip',
            SourceTree::has($path, 'Pipfile') => 'Pipenv',
            $pyproject !== '' => 'pip (pyproject.toml)',
            default => 'pip',
        };
    }

    /** The Dockerfile lines that put the dependencies into /opt/venv and the code into /app. */
    private function install(string $path, string $manager): string
    {
        switch ($manager) {
            case 'uv':
                return "ENV UV_PROJECT_ENVIRONMENT=/opt/venv\nCOPY pyproject.toml uv.lock ./\nRUN pip install uv && uv sync --frozen --no-dev --no-install-project\nCOPY . .\n";

            case 'Poetry':
                $copy = 'pyproject.toml'.(SourceTree::has($path, 'poetry.lock') ? ' poetry.lock' : '');

                return "COPY {$copy} ./\nRUN pip install poetry && poetry install --only main --no-root --no-interaction\nCOPY . .\n";

            case 'Pipenv':
                $lock = SourceTree::has($path, 'Pipfile.lock') ? '' : 'pipenv lock && ';

                return 'COPY Pipfile'.(SourceTree::has($path, 'Pipfile.lock') ? ' Pipfile.lock' : '')." ./\nRUN pip install pipenv && {$lock}pipenv requirements > /tmp/requirements.txt && pip install -r /tmp/requirements.txt\nCOPY . .\n";

            case 'pip (pyproject.toml)':
                return "COPY . .\nRUN pip install .\n";

            default:
                // requirements.txt may include other files or the project itself, which only exist once everything is copied.
                $requirements = (string) SourceTree::read($path, 'requirements.txt');

                if (preg_match('/^\s*(-r|-c|-e|--requirement|--constraint|--editable|\.|\/|file:)/m', $requirements) === 1) {
                    return "COPY . .\nRUN pip install -r requirements.txt\n";
                }

                return "COPY requirements.txt ./\nRUN pip install -r requirements.txt\nCOPY . .\n";
        }
    }

    /** The text of the dependency files, lower case, for spotting the framework. */
    private function dependencyText(string $path): string
    {
        return strtolower(
            (string) SourceTree::read($path, 'requirements.txt')."\n"
            .(string) SourceTree::read($path, 'pyproject.toml')."\n"
            .(string) SourceTree::read($path, 'Pipfile')."\n",
        );
    }

    private function mentions(string $dependencies, string $package): bool
    {
        return preg_match('/(^|[\s"\'\[,])'.preg_quote($package, '/').'([\s=<>~!\[;,"\']|$)/m', $dependencies) === 1;
    }

    /**
     * How the app is started: [the shell command, the server package it needs, a description].
     *
     * @return array{0: string, 1: string|null, 2: string}
     *
     * @throws DetectionFailed
     */
    private function startCommand(string $path, string $dependencies): array
    {
        $procfile = (string) SourceTree::read($path, 'Procfile');

        if (preg_match('/^web:\s*(.+)$/m', $procfile, $match)) {
            $command = trim($match[1]);

            if ($command === '' || preg_match('/[\x00-\x08\x0b-\x1f\x7f]/', $command) === 1) {
                throw new DetectionFailed('The "web:" line of the Procfile is empty or has unusual characters. Fix it or add a Dockerfile.');
            }

            return [$command, null, "the Procfile's web line ({$command})"];
        }

        if ($this->mentions($dependencies, 'django')) {
            $module = $this->djangoModule($path);

            return ["gunicorn {$module}:application --bind 0.0.0.0:\$PORT --workers 2 --access-logfile -", 'gunicorn', "gunicorn {$module}:application (Django)"];
        }

        foreach ([['fastapi', 'FastAPI'], ['starlette', 'Starlette'], ['quart', 'Quart']] as [$package, $class]) {
            if ($this->mentions($dependencies, $package)) {
                $target = $this->appObject($path, [$class]);

                return ["uvicorn {$target} --host 0.0.0.0 --port \$PORT", 'uvicorn', "uvicorn {$target} ({$class})"];
            }
        }

        if ($this->mentions($dependencies, 'flask')) {
            $target = $this->appObject($path, ['Flask']);

            return ["gunicorn {$target} --bind 0.0.0.0:\$PORT --workers 2 --access-logfile -", 'gunicorn', "gunicorn {$target} (Flask)"];
        }

        throw new DetectionFailed('Could not tell how to start this Python app. Add a Procfile with a line such as "web: gunicorn app:app" (or "web: uvicorn main:app --host 0.0.0.0 --port $PORT"), or add a Dockerfile.');
    }

    /**
     * The "module:variable" of the app object: the first file that creates one of the framework's
     * classes at the top level, or a create_app() factory.
     *
     * @param  list<string>  $classes
     *
     * @throws DetectionFailed
     */
    private function appObject(string $path, array $classes): string
    {
        $candidates = [];

        foreach (SourceTree::find($path, 'py', 2) as $file) {
            if (in_array(basename($file), self::APP_FILES, true) && ! str_contains($file, 'test')) {
                $candidates[] = $file;
            }
        }

        // The usual names first, and files closer to the top first.
        usort($candidates, fn (string $a, string $b) => [substr_count($a, '/'), array_search(basename($a), self::APP_FILES, true)] <=> [substr_count($b, '/'), array_search(basename($b), self::APP_FILES, true)]);

        $pattern = '/^([A-Za-z_][A-Za-z0-9_]*)\s*(?::[^=\n]+)?=\s*(?:\w+\.)*(?:'.implode('|', array_map(fn (string $c) => preg_quote($c, '/'), $classes)).')\s*\(/m';

        foreach ($candidates as $file) {
            $source = (string) SourceTree::read($path, $file);
            $module = $this->moduleOf($file);

            if (preg_match($pattern, $source, $match)) {
                return "{$module}:{$match[1]}";
            }

            if (preg_match('/^def\s+create_app\s*\(/m', $source) === 1) {
                return "{$module}:create_app()";
            }
        }

        throw new DetectionFailed('Could not find where the app is created (a line such as "app = '.$classes[0].'()" in main.py or app.py). Add a Procfile with a "web:" line, or a Dockerfile.');
    }

    /** "app/main.py" becomes "app.main"; "app/__init__.py" becomes "app". */
    private function moduleOf(string $file): string
    {
        $module = str_replace('/', '.', (string) preg_replace('/\.py$/', '', $file));
        $module = (string) preg_replace('/\.__init__$/', '', $module);

        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)*$/', $module)) {
            throw new DetectionFailed("The module name \"{$module}\" is not one Python can import. Add a Procfile with a \"web:\" line, or a Dockerfile.");
        }

        return $module;
    }

    /** The Django project's module that holds wsgi.py (next to settings). */
    private function djangoModule(string $path): string
    {
        $found = [];

        foreach (SourceTree::find($path, 'py', 2) as $file) {
            if (basename($file) === 'wsgi.py') {
                $found[] = $file;
            }
        }

        if (count($found) === 1) {
            return $this->moduleOf($found[0]);
        }

        throw new DetectionFailed($found === []
            ? 'This looks like a Django project but no wsgi.py was found. Add a Procfile with a "web:" line, or a Dockerfile.'
            : 'Found several wsgi.py files ('.implode(', ', $found).'). Add a Procfile with a "web:" line to choose one, or a Dockerfile.');
    }
}
