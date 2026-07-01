<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class CertificateNumberService
{
    /** Must be called from inside a database transaction. */
    public function reserve(int $year): array
    {
        DB::table('certificate_sequences')->insertOrIgnore([
            'year' => $year, 'last_number' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $row = DB::table('certificate_sequences')->where('year', $year)->lockForUpdate()->first();
        $number = ((int) $row->last_number) + 1;
        DB::table('certificate_sequences')->where('year', $year)->update([
            'last_number' => $number, 'updated_at' => now(),
        ]);

        return ['number' => $number, 'year' => $year, 'folio' => sprintf('CC-%06d-%d', $number, $year)];
    }
}
