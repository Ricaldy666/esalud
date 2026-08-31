<?php

namespace App\Domain\Auth\Requests;

use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;

/**
 * Exige la password actual antes de una accion sensible de 2FA (iniciar
 * enrolamiento, desactivar, regenerar codigos de recuperacion) -- defensa
 * ante secuestro de sesion, no solo confiar en que la cookie sigue viva.
 */
class TwoFactorSensitiveActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string'],
        ];
    }

    public function withValidator(ValidatorContract $validator): void
    {
        $validator->after(function (ValidatorContract $validator) {
            $user = $this->user();

            if ($user !== null && !Hash::check((string) $this->input('current_password'), $user->password)) {
                $validator->errors()->add('current_password', 'La contraseña actual no es correcta.');
            }
        });
    }
}
