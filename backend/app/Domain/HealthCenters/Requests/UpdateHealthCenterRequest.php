<?php

namespace App\Domain\HealthCenters\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateHealthCenterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $centerId = $this->route('health_center');

        return [
            'name' => 'sometimes|string|max:200',
            'code_deis' => [
                'sometimes',
                'string',
                'max:20',
                Rule::unique('health_centers', 'code_deis')->ignore($centerId),
            ],
            'type' => 'sometimes|string|in:CESFAM,CECOSF,PSR,SAPU,SAR,OTRO',
            'address' => 'nullable|string|max:255',
            'commune' => 'nullable|string|max:100',
            'is_active' => 'boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'code_deis.unique' => 'El código DEIS ya está registrado.',
            'type.in' => 'El tipo de centro debe ser: CESFAM, CECOSF, PSR, SAPU, SAR u OTRO.',
        ];
    }
}
