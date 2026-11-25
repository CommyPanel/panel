<?php

namespace App\Http\Requests;

use App\Deploy\CommandLine;
use App\Deploy\ReleaseScripts;
use App\Enums\DeployStrategy;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Step 3 of the app wizard: runtime settings and environment variables.
 */
class FinishSetupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('release_script')) {
            $this->merge(['release_script' => ReleaseScripts::normalize($this->input('release_script'))]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'container_port' => ['required', 'integer', 'between:1,65535'],
            'health_path' => ['required', 'string', 'max:200', 'regex:#^/[A-Za-z0-9._~/%?=&+-]*$#'],
            'health_timeout' => ['nullable', 'integer', 'between:10,1800'],
            // `sometimes`: a form that does not send it leaves the mode as it is.
            'deploy_strategy' => ['sometimes', Rule::enum(DeployStrategy::class)],
            'release_command' => ['nullable', 'string', 'max:1000', CommandLine::rule()],
            'release_script' => ['nullable', 'string', 'max:'.ReleaseScripts::MAX_LENGTH, function (string $attribute, mixed $value, Closure $fail) {
                if (str_contains((string) $value, "\0")) {
                    $fail(__('The script holds a character that cannot be text.'));
                }
            }],
            'memory_limit_mb' => ['nullable', 'integer', 'between:16,65536'],
            'cpu_limit' => ['nullable', 'numeric', 'between:0.05,64'],
            'env' => ['nullable', 'string', 'max:200000'],
            // A Laravel app's choices (PHP version, how assets are built).
            'stack_options' => ['sometimes', 'nullable', 'array'],
            'stack_options.php' => ['nullable', Rule::in(['8.2', '8.3', '8.4', '8.5'])],
            'stack_options.node_manager' => ['nullable', Rule::in(['npm', 'pnpm', 'yarn', 'none'])],
            'stack_options.node' => ['nullable', 'integer', 'between:18,30'],
            'stack_options.ssr' => ['nullable', 'boolean'],
        ];
    }
}
