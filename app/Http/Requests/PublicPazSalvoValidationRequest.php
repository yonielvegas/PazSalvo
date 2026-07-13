<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

class PublicPazSalvoValidationRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $issuedDate = trim((string) $this->input('fecha_emision'));
        if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $issuedDate)) {
            try {
                $parsedDate = Carbon::createFromFormat('d/m/Y', $issuedDate, 'America/Panama');
                if ($parsedDate->format('d/m/Y') === $issuedDate) {
                    $issuedDate = $parsedDate->toDateString();
                }
            } catch (\Throwable) {
                // Keep the original value so the date rule returns the validation message.
            }
        }

        $this->merge([
            'folio' => strtoupper(trim((string) $this->input('folio'))),
            'fecha_emision' => $issuedDate,
        ]);
    }

    public function rules(): array
    {
        return [
            'folio' => ['required', 'string', 'regex:/^CC-\d{6}-\d{4}$/'],
            'fecha_emision' => ['required', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'folio.required' => 'Ingrese el folio del Paz y Salvo.',
            'folio.regex' => 'Ingrese el folio con el formato CC-000000-2026.',
            'fecha_emision.required' => 'Ingrese la fecha de emisión.',
            'fecha_emision.date' => 'Ingrese la fecha de emisión con el formato día/mes/año.',
        ];
    }
}
