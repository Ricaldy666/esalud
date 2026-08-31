<?php

namespace App\Domain\Auth\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TwoFactorChallengeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Acepta tanto un TOTP de 6 digitos como un codigo de
            // recuperacion (formato xxxx-xxxx) -- el servicio decide cual es.
            'code' => ['required', 'string', 'max:20'],
        ];
    }
}
