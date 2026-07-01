<?php

namespace Tests\Unit;

use App\Services\CertificateNumberService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CertificateNumberServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reserves_unique_padded_numbers_per_year(): void
    {
        $service = app(CertificateNumberService::class);
        $first = DB::transaction(fn () => $service->reserve(2026));
        $second = DB::transaction(fn () => $service->reserve(2026));
        $nextYear = DB::transaction(fn () => $service->reserve(2027));
        $this->assertSame('CC-000001-2026', $first['folio']);
        $this->assertSame('CC-000002-2026', $second['folio']);
        $this->assertSame('CC-000001-2027', $nextYear['folio']);
    }
}
