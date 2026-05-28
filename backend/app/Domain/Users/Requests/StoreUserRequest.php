<?php

namespace App\Domain\Users\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:150',
            'rut' => 'required|string|max:12|unique:users,rut|regex:/^\d{7,8}-[0-9kK]$/',
            'email' => 'required|email|max:150|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
            'health_center_id' => 'nullable|exists:health_centers,id',
            'role' => 'required|exists:roles,name',
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
