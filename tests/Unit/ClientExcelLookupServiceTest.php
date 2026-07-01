<?php

namespace Tests\Unit;

use App\Services\ClientExcelLookupService;
use Tests\TestCase;

class ClientExcelLookupServiceTest extends TestCase
{
    public function test_it_finds_a_client_in_the_master_workbook(): void
    {
        $client = app(ClientExcelLookupService::class)->findByClientNumber('34787');
        $this->assertSame('LEIDA AMANDA TERRADO SANTAMARIA', $client['holder_name']);
        $this->assertSame('SAN MIGUELITO', $client['district']);
    }
}
