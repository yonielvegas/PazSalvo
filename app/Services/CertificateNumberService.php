<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class CertificateNumberService
{
    /** Must be called from inside a database transaction. */
    public function lockYear(int $year): void
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Certificate numbers require an active database transaction.');
        }
        DB::table('certificate_sequences')->insertOrIgnore([
            'year' => $year, 'last_number' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('certificate_sequences')->where('year', $year)->lockForUpdate()->first();
    }

    /** Reservation is committed only with the completed certificate. */
    public function reserve(int $year): array
    {
        $this->lockYear($year);
        $row = DB::table('certificate_sequences')->where('year', $year)->first();
        $number = ((int) $row->last_number) + 1;
        DB::table('certificate_sequences')->where('year', $year)->update([
            'last_number' => $number, 'updated_at' => now(),
        ]);

        return ['number' => $number, 'year' => $year, 'folio' => sprintf('CC-%06d-%d', $number, $year)];
    }
}
