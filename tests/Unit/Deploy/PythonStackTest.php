<?php

use App\Deploy\Stacks\DetectionFailed;
use App\Deploy\Stacks\StackDetector;
use App\Enums\Stack;

require_once __DIR__.'/SourceHelpers.php';

function pythonPlan(array $files, int $port = 8000)
{
    return (new StackDetector)->plan(sourceTree($files), $port);
}

const FASTAPI_MAIN = "from fastapi import FastAPI\n\napp = FastAPI()\n";

describe('detection', function () {
    it('picks Python from the dependency files, after the stacks with more specific markers', function () {
        expect(pythonPlan(['requirements.txt' => 'flask', 'app.py' => "app = Flask(__name__)\n"])->stack)->toBe(Stack::Python)
            ->and(pythonPlan(['pyproject.toml' => '[project]', 'Procfile' => 'web: x'])->stack)->toBe(Stack::Python)
            ->and(pythonPlan(['Pipfile' => '[packages]', 'Procfile' => 'web: x'])->stack)->toBe(Stack::Python);

        // A repository Dockerfile, and Go or Node markers, still win: tooling often has a requirements.txt.
        expect(pythonPlan(['Dockerfile' => 'FROM scratch', 'requirements.txt' => 'flask'])->stack)->toBe(Stack::Dockerfile)
            ->and(pythonPlan(['package.json' => json_encode(['scripts' => ['start' => 'x']]), 'requirements.txt' => 'mkdocs'])->stack)->toBe(Stack::Node)
            ->and(pythonPlan(['go.mod' => "module x\n\ngo 1.22\n", 'main.go' => "package main\n", 'requirements.txt' => 'x'])->stack)->toBe(Stack::Go);
    });

    it('still reports an unknown project, now naming Python', function () {
        expect(fn () => pythonPlan(['README.md' => 'hi']))->toThrow(DetectionFailed::class, 'Python');
    });
});

describe('the start command', function () {
    it('starts FastAPI with uvicorn, installing it when the project does not list it', function () {
        $plan = pythonPlan(['requirements.txt' => "fastapi==0.115.0\n", 'main.py' => FASTAPI_MAIN], 9000);

        expect($plan->dockerfile)
            ->toContain('FROM python:3.12 AS build')
            ->toContain('FROM python:3.12-slim')
            ->toContain('RUN pip install uvicorn')
            // Poetry and uv only install into the venv when they are told it is the active one.
            ->toContain('ENV VIRTUAL_ENV=/opt/venv PATH=/opt/venv/bin:$PATH')
            ->toContain('USER app')
            ->toContain('PORT=9000')
            ->toContain('EXPOSE 9000')
            ->toContain('CMD ["sh","-c","exec uvicorn main:app --host 0.0.0.0 --port $PORT"]')
            ->and($plan->notes[0])->toContain('uvicorn main:app (FastAPI)');
    });

    it('does not install uvicorn again when it is already a dependency', function () {
        $plan = pythonPlan(['requirements.txt' => "fastapi\nuvicorn[standard]>=0.30\n", 'main.py' => FASTAPI_MAIN]);

        expect($plan->dockerfile)->not->toContain('RUN pip install uvicorn');
    });

    it('finds the app object in a package, using the dotted module path', function () {
        $plan = pythonPlan(['requirements.txt' => 'fastapi', 'app/main.py' => "from fastapi import FastAPI\napi = FastAPI(title='x')\n", 'app/__init__.py' => '']);

        expect($plan->dockerfile)->toContain('uvicorn app.main:api ');
    });

    it('starts Flask with gunicorn, or a create_app factory', function () {
        $flask = pythonPlan(['requirements.txt' => "Flask==3.0\n", 'app.py' => "from flask import Flask\napp = Flask(__name__)\n"]);
        $factory = pythonPlan(['requirements.txt' => "flask\n", 'app/__init__.py' => "def create_app():\n    return None\n"]);

        expect($flask->dockerfile)->toContain('RUN pip install gunicorn')->toContain('exec gunicorn app:app --bind 0.0.0.0:$PORT')
            ->and($factory->dockerfile)->toContain('gunicorn app:create_app() --bind');
    });

    it('starts Django with gunicorn on its wsgi module', function () {
        $plan = pythonPlan(['requirements.txt' => "Django>=5\n", 'manage.py' => '', 'mysite/wsgi.py' => 'application = None']);

        expect($plan->dockerfile)->toContain('gunicorn mysite.wsgi:application --bind 0.0.0.0:$PORT');
    });

    it('asks for help when Django has no wsgi.py or several', function () {
        expect(fn () => pythonPlan(['requirements.txt' => 'django']))->toThrow(DetectionFailed::class, 'no wsgi.py')
            ->and(fn () => pythonPlan(['requirements.txt' => 'django', 'a/wsgi.py' => '', 'b/wsgi.py' => '']))->toThrow(DetectionFailed::class, 'several wsgi.py');
    });

    it('lets a Procfile web line win, keeps $PORT and encodes quotes', function () {
        $plan = pythonPlan(['requirements.txt' => 'flask', 'Procfile' => "release: x\nweb: gunicorn -k gthread \"app:create()\" -b 0.0.0.0:\$PORT\n"]);

        expect($plan->dockerfile)->toContain('CMD ["sh","-c","exec gunicorn -k gthread \"app:create()\" -b 0.0.0.0:$PORT"]')
            ->not->toContain('RUN pip install gunicorn');
    });

    it('refuses to guess when there is nothing to go on', function () {
        expect(fn () => pythonPlan(['requirements.txt' => "requests\n"]))->toThrow(DetectionFailed::class, 'Procfile')
            ->and(fn () => pythonPlan(['requirements.txt' => 'fastapi', 'util.py' => 'x = 1']))->toThrow(DetectionFailed::class, 'where the app is created')
            ->and(fn () => pythonPlan(['requirements.txt' => 'flask', 'Procfile' => "web:   \n"]))->toThrow(DetectionFailed::class);
    });

    it('does not mistake a test file for the app', function () {
        expect(fn () => pythonPlan(['requirements.txt' => 'fastapi', 'tests/test_main.py' => FASTAPI_MAIN, 'test_app.py' => FASTAPI_MAIN]))->toThrow(DetectionFailed::class);
    });
});

describe('the Python version', function () {
    it('reads .python-version, runtime.txt and the project requirement', function (array $files, string $expected) {
        expect(pythonPlan(['requirements.txt' => 'fastapi', 'main.py' => FASTAPI_MAIN, ...$files])->dockerfile)->toContain("FROM python:{$expected}-slim");
    })->with([
        '.python-version' => [['.python-version' => "3.11.4\n"], '3.11'],
        'runtime.txt' => [['runtime.txt' => "python-3.10.12\n"], '3.10'],
        'pyproject requires-python compatible' => [['pyproject.toml' => "[project]\nrequires-python = \"~=3.13\"\n"], '3.13'],
        'poetry caret' => [['pyproject.toml' => "[tool.poetry.dependencies]\npython = \"^3.10\"\n"], '3.10'],
        'exact' => [['pyproject.toml' => "[project]\nrequires-python = \"==3.11.*\"\n"], '3.11'],
        'a floor alone means the default' => [['pyproject.toml' => "[project]\nrequires-python = \">=3.10\"\n"], '3.12'],
        'too old to use' => [['.python-version' => '3.7'], '3.12'],
        'not a version' => [['.python-version' => "pypy3.10\n"], '3.10'],
        'nothing said' => [[], '3.12'],
    ]);
});

describe('installing the dependencies', function () {
    it('uses pip with requirements.txt, copying it first for the build cache', function () {
        $plan = pythonPlan(['requirements.txt' => "flask\n", 'app.py' => "app = Flask(__name__)\n"]);

        expect($plan->dockerfile)->toContain("COPY requirements.txt ./\nRUN pip install -r requirements.txt\nCOPY . .");
    });

    it('copies everything first when requirements.txt points at other files or the project', function (string $line) {
        $plan = pythonPlan(['requirements.txt' => "flask\n{$line}\n", 'app.py' => "app = Flask(__name__)\n"]);

        expect($plan->dockerfile)->toContain("COPY . .\nRUN pip install -r requirements.txt");
    })->with(['-r base.txt', '-e .', './libs/mine', '-c constraints.txt']);

    it('uses uv for a uv.lock, Poetry for [tool.poetry], Pipenv for a Pipfile and pip for a plain pyproject', function () {
        $uv = pythonPlan(['pyproject.toml' => "[project]\ndependencies = [\"fastapi\"]\n", 'uv.lock' => '', 'main.py' => FASTAPI_MAIN]);
        $poetry = pythonPlan(['pyproject.toml' => "[tool.poetry]\nname='x'\n[tool.poetry.dependencies]\nflask='*'\n", 'poetry.lock' => '', 'app.py' => "app = Flask(__name__)\n"]);
        $pipenv = pythonPlan(['Pipfile' => "[packages]\nflask = '*'\n", 'app.py' => "app = Flask(__name__)\n"]);
        $plain = pythonPlan(['pyproject.toml' => "[project]\ndependencies = [\"fastapi\"]\n", 'main.py' => FASTAPI_MAIN]);

        expect($uv->dockerfile)->toContain('uv sync --frozen --no-dev --no-install-project')->toContain('UV_PROJECT_ENVIRONMENT=/opt/venv')
            ->and($poetry->dockerfile)->toContain('COPY pyproject.toml poetry.lock ./')->toContain('poetry install --only main --no-root')
            ->and($pipenv->dockerfile)->toContain('pipenv lock && pipenv requirements')
            ->and($plain->dockerfile)->toContain('RUN pip install .')
            ->and($uv->notes[0])->toContain('uv')->and($poetry->notes[0])->toContain('Poetry');
    });

    it('does not lock a Pipfile that already has a lock', function () {
        $plan = pythonPlan(['Pipfile' => "[packages]\nflask='*'\n", 'Pipfile.lock' => '{}', 'app.py' => "app = Flask(__name__)\n"]);

        expect($plan->dockerfile)->toContain('COPY Pipfile Pipfile.lock ./')->not->toContain('pipenv lock');
    });
});

describe('what the repository cannot make the Dockerfile do', function () {
    it('refuses module names that are not identifiers', function (string $file) {
        expect(fn () => pythonPlan(['requirements.txt' => 'fastapi', $file => FASTAPI_MAIN]))->toThrow(DetectionFailed::class);
    })->with(['my app/main.py', 'a"b/main.py', 'x;rm/main.py', '1bad/main.py']);

    it('puts nothing but validated pieces into the Dockerfile', function () {
        // The variable name on the app line cannot carry shell text either: it must be an identifier.
        $plan = pythonPlan(['requirements.txt' => 'fastapi', 'main.py' => "from fastapi import FastAPI\nok_name = FastAPI()\n"]);

        expect($plan->dockerfile)->toContain('main:ok_name')
            ->and($plan->dockerfile)->not->toContain('$(')->not->toContain('`');
    });

    it('runs as a user that is not root, with an ignore file for secrets and caches', function () {
        $plan = pythonPlan(['requirements.txt' => 'fastapi', 'main.py' => FASTAPI_MAIN]);

        expect($plan->dockerfile)->toContain('useradd --create-home --uid 10001 app')->toContain('USER app')
            ->and($plan->dockerignore)->toContain('.env*')->toContain('.git')->toContain('__pycache__');
    });
});
