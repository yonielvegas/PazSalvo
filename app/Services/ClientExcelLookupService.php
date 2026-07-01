<?php

namespace App\Services;

use App\Exceptions\ExcelLookupException;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ClientExcelLookupService
{
    public function findByClientNumber(string $clientNumber): ?array
    {
        $disk = Storage::disk(config('paz-salvo.disk'));
        $relativePath = config('paz-salvo.clients_excel');
        if (! $disk->exists($relativePath)) {
            throw new ExcelLookupException('No se encontró el Excel maestro de clientes.');
        }

        $sheet = IOFactory::load($disk->path($relativePath))->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, false);
        if ($rows === []) {
            throw new ExcelLookupException('El Excel maestro está vacío.');
        }

        $required = ['nac', 'nombre completo', 'distrito', 'corregimiento', 'direccion'];
        $headers = array_map(fn ($value) => $this->normalizeHeader((string) $value), array_shift($rows));
        $map = array_flip($headers);
        foreach ($required as $header) {
            if (! array_key_exists($header, $map)) {
                throw new ExcelLookupException("Falta la columna requerida: {$header}.");
            }
        }

        $matches = [];
        foreach ($rows as $row) {
            $nac = trim((string) ($row[$map['nac']] ?? ''));
            if ($nac === $clientNumber) {
                $matches[] = [
                    'client_number' => $nac,
                    'holder_name' => trim((string) ($row[$map['nombre completo']] ?? '')),
                    'district' => trim((string) ($row[$map['distrito']] ?? '')),
                    'corregimiento' => trim((string) ($row[$map['corregimiento']] ?? '')),
                    'address' => trim((string) ($row[$map['direccion']] ?? '')),
                ];
            }
        }

        if (count($matches) > 1) {
            throw new ExcelLookupException('El NAC aparece más de una vez en el Excel maestro.');
        }

        return $matches[0] ?? null;
    }

    private function normalizeHeader(string $header): string
    {
        $header = mb_strtolower(trim($header));

        return strtr($header, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u']);
    }
}
