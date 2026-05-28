<?php

namespace App\Domain\Users\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $userId = $this->route('user');

        return [
            'name' => 'sometimes|string|max:150',
            'rut' => [
                'sometimes',
                'string',
                'max:12',
                Rule::unique('users', 'rut')->ignore($userId),
                'regex:/^\d{7,8}-[0-9kK]$/',
            ],
            'email' => [
                'sometimes',
                'email',
                'max:150',
                Rule::unique('users', 'email')->ignore($userId),
            ],
            'password' => 'nullable|string|min:8|confirmed',
            'health_center_id' => 'nullable|exists:health_centers,id',
            'role' => 'sometimes|exists:roles,name',
            'is_active' => 'boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'rut.regex' => 'El RUT debe tener formato chileno válido (ej: 12345678-5).',
            'rut.unique' => 'Este RUT ya está registrado.',
            'role.exists' => 'El rol seleccionado no es válido.',
        ];
    }
}
