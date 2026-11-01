<?php

namespace App\Http\Requests;

use App\Deploy\CommandLine;
use App\Deploy\Source\GitUrl;
use App\Enums\SourceType;
use App\Models\App;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

abstract class AppSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $upload = $this->route('app') instanceof App && $this->route('app')->source_type === SourceType::Upload;
        $git = $upload ? [] : [
            'git_url' => ['required', 'string', 'max:500', function (string $attribute, mixed $value, Closure $fail) {
                if (! GitUrl::isValid((string) $value, (bool) config('commypanel.deploy.git_allow_local'))) {
                    $fail('Use an https:// or ssh Git URL, for example https://github.com/acme/shop.git or git@github.com:acme/shop.git.');
                }
            }],
            'git_branch' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail) {
                if (! GitUrl::isValidBranch((string) $value)) {
                    $fail('That is not a valid branch name.');
                }
            }],
        ];

        return [
            ...$git,
            'container_port' => ['required', 'integer', 'between:1,65535'],
            'health_path' => ['required', 'string', 'max:200', 'regex:#^/[A-Za-z0-9._~/%?=&+-]*$#'],
            'release_command' => ['nullable', 'string', 'max:1000', CommandLine::rule()],
            'memory_limit_mb' => ['nullable', 'integer', 'between:16,65536'],
            'cpu_limit' => ['nullable', 'numeric', 'between:0.05,64'],
        ];
    }
}
