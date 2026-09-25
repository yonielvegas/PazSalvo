<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

class StoreClientExcelRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                File::types(['xlsx', 'xls'])->max(config('paz-salvo.clients_excel_max_kb')),
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! in_array(strtolower($value->getClientOriginalExtension()), ['xlsx', 'xls'], true)) {
                        $fail('El archivo debe tener extensión .xlsx o .xls.');
                    }
                },
            ],
        ];
    }
}
