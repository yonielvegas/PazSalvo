<?php

namespace Tests\Feature;

use App\Exceptions\PdfConversionException;
use App\Models\PazSalvo;
use App\Models\User;
use App\Services\CertificateNumberService;
use App\Services\ClientExcelLookupService;
use App\Services\PazSalvoExcelService;
use App\Services\PazSalvoService;
use App\Services\PdfConversionService;
use App\Services\QrCodeService;
use App\Services\WidergyDebtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class PazSalvoServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_pdf_keeps_error_record_and_consumes_folio(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $widergy = Mockery::mock(WidergyDebtService::class);
        $widergy->shouldReceive('consult')->once()->andReturn($this->payload());
        $lookup = Mockery::mock(ClientExcelLookupService::class);
        $lookup->shouldReceive('findByClientNumber')->once()->andReturn(null);
        $qr = Mockery::mock(QrCodeService::class);
        $qr->shouldReceive('generate')->once()->andReturnUsing(function () {
            Storage::disk('local')->put('generated/qr.png', 'qr');

            return 'generated/qr.png';
        });
        $excel = Mockery::mock(PazSalvoExcelService::class);
        $excel->shouldReceive('generate')->once()->andReturnUsing(function () {
            Storage::disk('local')->put('generated/test.xlsx', 'xlsx');

            return 'generated/test.xlsx';
        });
        $pdf = Mockery::mock(PdfConversionService::class);
        $pdf->shouldReceive('convertXlsxToPdf')->once()->andThrow(new PdfConversionException('LibreOffice falló'));
        $service = new PazSalvoService($widergy, $lookup, app(CertificateNumberService::class), $qr, $excel, $pdf);

        try {
            $service->generate('34787', $user);
            $this->fail('Expected exception');
        } catch (PdfConversionException) {
        }

        $document = PazSalvo::firstOrFail();
        $this->assertSame('CC-000001-2026', $document->folio);
        $this->assertSame(PazSalvo::ERROR, $document->status);
        $this->assertSame('LibreOffice falló', $document->generation_error);
        Storage::disk('local')->assertMissing('generated/qr.png');
        Storage::disk('local')->assertMissing('generated/test.xlsx');
        $next = \DB::transaction(fn () => app(CertificateNumberService::class)->reserve(2026));
        $this->assertSame('CC-000002-2026', $next['folio']);
    }

    private function payload(): array
    {
        return ['job' => ['job_id' => 'job'], 'result' => ['account' => ['client_number' => '34787', 'holder_name' => 'CLIENTE', 'address' => 'CALLE 1', 'city' => 'PANAMÁ', 'rate' => 'Residencial'], 'balances' => ['total_balance' => 0, 'expired_balance' => 0, 'non_expired_balance' => 0], 'debts' => [], 'raw' => []]];
    }
}
