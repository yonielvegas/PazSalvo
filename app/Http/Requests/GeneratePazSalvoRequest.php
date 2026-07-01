<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GeneratePazSalvoRequest extends FormRequest
{
    public function rules(): array
    {
        return ['query_token' => ['required', 'uuid']];
    }
}
