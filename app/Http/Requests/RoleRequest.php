<?php

namespace App\Http\Requests;

use App\Auth\Permissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RoleRequest extends FormRequest
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
        $role = $this->route('role');

        return [
            'name' => [
                'required', 'string', 'min:2', 'max:50', 'regex:/^[A-Za-z0-9][A-Za-z0-9 _-]*$/',
                Rule::unique('roles', 'name')->where('guard_name', 'web')->ignore($role),
            ],
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', Rule::in(Permissions::names())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.regex' => __('Use letters, numbers, spaces, dashes and underscores.'),
            'name.unique' => __('There is already a role with that name.'),
        ];
    }
}
