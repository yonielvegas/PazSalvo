<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ConsultPazSalvoRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['client_number' => trim((string) $this->input('client_number'))]);
    }

    public function rules(): array
    {
        return ['client_number' => ['required', 'string', 'regex:/^\d{1,12}$/']];
    }

    public function messages(): array
    {
        return [
            'client_number.required' => 'Ingrese el NAC del cliente.',
            'client_number.regex' => 'El NAC debe contener solo números y máximo 12 dígitos.',
        ];
    }
}
