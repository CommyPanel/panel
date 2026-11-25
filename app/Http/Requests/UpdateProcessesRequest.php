<?php

namespace App\Http\Requests;

use App\Deploy\AppProcesses;
use App\Enums\ProcessKind;
use App\Models\App;
use App\Models\AppProcess;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * The Services list of an app: the whole list is sent and replaces the old one.
 */
class UpdateProcessesRequest extends FormRequest
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
        $app = $this->route('app');
        // A container is called <slug>-<release>-<name>, plus a number when there are copies; 63 characters at most.
        $longest = $app instanceof App ? max(2, min(31, 63 - strlen($app->slug) - 11)) : 31;

        return [
            'processes' => ['present', 'array', 'max:20'],
            'processes.*.name' => ['required', 'string', 'regex:/^[a-z][a-z0-9-]*[a-z0-9]$/', 'min:2', 'max:'.$longest, Rule::notIn(AppProcesses::RESERVED), 'distinct', function (string $attribute, mixed $value, Closure $fail) {
                if (preg_match('/-\d+$/', (string) $value) === 1) {
                    $fail(__('A name cannot end in a dash and a number.'));
                }
            }],
            'processes.*.kind' => ['required', Rule::enum(ProcessKind::class)],
            'processes.*.command' => ['nullable', 'string', 'max:'.AppProcess::MAX_COMMAND_LENGTH, function (string $attribute, mixed $value, Closure $fail) {
                if (trim((string) $value) === '') {
                    return;
                }

                try {
                    AppProcess::split((string) $value);
                } catch (InvalidArgumentException $e) {
                    $fail($e->getMessage());
                }
            }],
            'processes.*.enabled' => ['required', 'boolean'],
            'processes.*.replicas' => ['nullable', 'integer', 'between:1,10'],
            'processes.*.overlap' => ['nullable', Rule::in([AppProcess::OVERLAP, AppProcess::STOP_FIRST])],
            'processes.*.memory_limit_mb' => ['nullable', 'integer', 'between:16,65536'],
            'processes.*.stop_grace_seconds' => ['nullable', 'integer', 'between:1,600'],
        ];
    }

    /**
     * A custom process has nothing to run without a command; the other kinds have a usual one.
     *
     * @return array<int, Closure>
     */
    public function after(): array
    {
        return [function ($validator) {
            foreach ((array) $this->input('processes') as $i => $row) {
                $command = trim((string) ($row['command'] ?? ''));

                if (($row['kind'] ?? null) === ProcessKind::Custom->value && $command === '') {
                    $validator->errors()->add("processes.{$i}.command", __('Enter the command this process runs.'));
                }
            }
        }];
    }
}
