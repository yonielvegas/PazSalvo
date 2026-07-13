<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class GeneratePazSalvoRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'query_token' => ['required', 'uuid'],
            'numero_factura' => ['required', 'string', 'regex:/^\d{6}$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'numero_factura.required' => 'El número de factura es obligatorio.',
            'numero_factura.regex' => 'El número de factura debe contener exactamente 6 dígitos numéricos.',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            back()
                ->withErrors($validator)
                ->withInput()
                ->with('result', $this->session()->get('paz_salvo_result'))
        );
    }
}
