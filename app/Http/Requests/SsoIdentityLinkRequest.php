<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class SsoIdentityLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('manage-users');
    }

    public static function selectionRules(): array
    {
        return ['query' => ['required', 'string', 'min:3', 'max:80', 'regex:/\A[^\x00-\x1f\x7f]+\z/uD'],
            'page' => ['required', 'integer', 'min:1', 'max:10'],
            'issuer' => ['required', 'string', 'max:255'],
            'subject' => ['required', 'string', 'regex:/\A[A-Za-z0-9_-]{1,255}\z/D'],
            'confirmed' => ['required', 'accepted']];
    }

    public function rules(): array
    {
        return self::selectionRules();
    }
}
