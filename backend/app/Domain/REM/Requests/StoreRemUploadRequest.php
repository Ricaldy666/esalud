<?php

namespace App\Domain\REM\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreRemUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'max:10240',
                'mimes:xlsx,xlsm,xls',
            ],
            'year' => ['required', 'integer', 'between:2015,2030'],
            'month' => ['required', 'integer', 'between:1,12'],
            'rem_type' => ['required', 'string', 'in:A,BM,BS,D,P'],
            'health_center_id' => ['required', 'integer', 'exists:health_centers,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'Debe seleccionar un archivo REM.',
            'file.file' => 'El archivo subido no es válido.',
            'file.max' => 'El archivo no puede superar los 10 MB.',
            'file.mimes' => 'El archivo debe ser formato Excel (.xlsx, .xlsm o .xls).',
            'year.between' => 'El año debe estar entre 2015 y 2030.',
            'month.between' => 'El mes debe estar entre 1 y 12.',
            'rem_type.in' => 'El tipo REM debe ser uno de: A, BM, BS, D, P.',
            'health_center_id.exists' => 'El centro de salud seleccionado no existe.',
        ];
    }
}
