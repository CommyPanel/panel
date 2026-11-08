<?php

namespace App\Http\Requests;

use App\Deploy\Source\GitUrl;
use App\Enums\SourceType;
use App\Enums\Stack;
use App\FastApps\FastApps;
use App\GitHub\GitHubApp;
use App\GitHub\GitHubFailed;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Step 1 of the app wizard: name, optional type, and where the code comes from.
 */
class StoreAppRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The fields of the chosen quick-install app: each has its own rules.
     *
     * @return array<string, array<int, mixed>>
     */
    private function templateRules(): array
    {
        $template = $this->input('source') === 'template' ? FastApps::find((string) $this->input('template')) : null;
        $rules = [];

        foreach ($template?->fields() ?? [] as $field) {
            $rules["settings.{$field->key}"] = $field->rules;
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $template = $this->input('source') === 'template' ? FastApps::find((string) $this->input('template')) : null;
        $names = [];

        foreach ($template?->fields() ?? [] as $field) {
            $names["settings.{$field->key}"] = mb_strtolower($field->label);
        }

        return $names;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'stack_hint' => ['nullable', Rule::in([Stack::Dotnet->value, Stack::Go->value, Stack::Node->value])],
            'source' => ['required', Rule::in([SourceType::Git->value, SourceType::Upload->value, SourceType::Template->value, 'github'])],
            'template' => ['exclude_unless:source,template', 'required', Rule::in(array_keys(FastApps::all()))],
            'settings' => ['exclude_unless:source,template', 'required', 'array'],
            ...$this->templateRules(),
            'github_repo' => ['exclude_unless:source,github', 'required', 'string', function (string $attribute, mixed $value, Closure $fail) {
                // Only a repository the installation was given, as GitHub lists it now.
                try {
                    $known = array_column(app(GitHubApp::class)->repositories(), 'name');
                } catch (GitHubFailed $e) {
                    $fail($e->getMessage());

                    return;
                }

                if (! in_array($value, $known, true)) {
                    $fail(__('That repository is not one the GitHub app may read. Give it access on GitHub first.'));
                }
            }],
            'git_url' => ['exclude_unless:source,git', 'required', 'string', 'max:500', function (string $attribute, mixed $value, Closure $fail) {
                if (! GitUrl::isValid((string) $value, (bool) config('commypanel.deploy.git_allow_local'))) {
                    $fail(__('Use an https:// or ssh Git URL, for example https://github.com/acme/shop.git or git@github.com:acme/shop.git.'));
                }
            }],
            'github_auto_deploy' => ['exclude_unless:source,github', 'nullable', 'boolean'],
            'git_branch' => ['exclude_unless:source,git,github', 'required', 'string', function (string $attribute, mixed $value, Closure $fail) {
                if (! GitUrl::isValidBranch((string) $value)) {
                    $fail(__('That is not a valid branch name.'));
                }
            }],
        ];
    }
}
