<?php

namespace App\Http\Requests;

use App\I18n\Locales;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
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
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'locale' => ['nullable', 'string', Rule::in(array_keys(Locales::available()))],
            'role' => ['nullable', Rule::exists('roles', 'id')->where('guard_name', 'web')],
        ];
    }
}
