<?php

namespace App\Support;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class RoleCode
{
    public static function fromName(string $name): string
    {
        $code = strtolower(Str::ascii(trim($name)));
        $code = preg_replace('/[^a-z0-9_.:-]+/', '_', $code);
        $code = preg_replace('/^[^a-z0-9]+/', '', $code);

        self::validate($code);

        return $code;
    }

    public static function validate(string $code): void
    {
        if (! preg_match('/\A[a-z0-9][a-z0-9_.:-]{0,79}\z/D', $code)) {
            throw ValidationException::withMessages(['code' => 'El código debe contener de 1 a 80 caracteres ASCII minúsculos y comenzar con letra o número.']);
        }
    }
}
