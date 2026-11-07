<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RoleRequest extends FormRequest
{
    public function rules(): array
    {
        $role = $this->route('role');

        return [
            'code' => $role ? ['prohibited'] : ['sometimes', 'required', 'string', 'max:80', 'regex:/\A[a-z0-9][a-z0-9_.:-]{0,79}\z/D', Rule::unique('roles', 'code')->where('guard_name', 'web')],
            'name' => ['required', 'string', 'max:100', Rule::unique('roles', 'name')->where('guard_name', 'web')->ignore($role?->id)],
            'permissions' => ['array'],
            'permissions.*' => ['string', Rule::exists('permissions', 'name')->where('guard_name', 'web')],
        ];
    }
}
