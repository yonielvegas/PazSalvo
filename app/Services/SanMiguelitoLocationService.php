<?php

namespace App\Services;

class SanMiguelitoLocationService
{
    public function isAllowedCity(?string $city): bool
    {
        if ($city === null || trim($city) === '') {
            return false;
        }

        $normalized = $this->normalize($city);

        foreach (config('san-miguelito.allowed_cities') as $allowed) {
            if ($this->normalize($allowed) === $normalized) {
                return true;
            }
        }

        return false;
    }

    public function getErrorMessage(?string $city): string
    {
        if ($city === null || trim($city) === '') {
            return 'No se pudo confirmar que el cliente pertenece al distrito de San Miguelito.';
        }

        return "El cliente consultado no pertenece al distrito de San Miguelito. Corregimiento recibido: {$city}.";
    }

    public function validate(?string $city): array
    {
        $isValid = $this->isAllowedCity($city);
        $message = $isValid ? '' : $this->getErrorMessage($city);

        return [
            'is_valid' => $isValid,
            'message' => $message,
            'received_city' => $city,
        ];
    }

    private function normalize(string $value): string
    {
        $value = trim($value);
        $value = mb_strtoupper($value, 'UTF-8');
        $value = iconv('UTF-8', 'ASCII//TRANSLIT', $value);
        $value = preg_replace('/\s+/', ' ', $value);

        return $value;
    }
}
