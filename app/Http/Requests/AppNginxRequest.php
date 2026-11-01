<?php

namespace App\Http\Requests;

use App\Deploy\Traffic\SaveNginxCustom;
use Illuminate\Foundation\Http\FormRequest;

class AppNginxRequest extends FormRequest
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
        return ['custom' => ['nullable', 'string', 'max:'.SaveNginxCustom::MAX_LENGTH]];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['custom.max' => 'That is too long. The limit is '.(SaveNginxCustom::MAX_LENGTH / 1000).' thousand characters.'];
    }
}
