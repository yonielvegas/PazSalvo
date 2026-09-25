<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AgencyRequest extends FormRequest
{
    public function rules(): array
    {
        $agency = $this->route('agency');

        return [
            'code' => ['required', 'string', 'max:100', 'regex:/^[A-Z0-9-]+$/', Rule::unique('agencies', 'code')->ignore($agency?->id)],
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
