<?php

namespace Tests\Feature;

use App\Exceptions\PdfConversionException;
use App\Models\PazSalvo;
use App\Models\User;
use App\Models\UserSignature;
use App\Services\CertificateNumberService;
use App\Services\ClientExcelLookupService;
use App\Services\PazSalvoExcelService;
use App\Services\PazSalvoService;
use App\Services\PdfConversionService;
use App\Services\QrCodeService;
use App\Services\WidergyDebtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PazSalvoServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_generation_is_blocked_when_agency_has_no_active_supervisor(): void
    {
        $user = User::factory()->create();
        $widergy = Mockery::mock(WidergyDebtService::class);
        $widergy->shouldNotReceive('consult');
        $service = new PazSalvoService(
            $widergy,
            Mockery::mock(ClientExcelLookupService::class),
            app(CertificateNumberService::class),
            Mockery::mock(QrCodeService::class),
            Mockery::mock(PazSalvoExcelService::class),
            Mockery::mock(PdfConversionService::class),
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('No hay un jefe de agencia activo con firma configurada para esta agencia.');
        $service->generate('34787', $user);
    }

    private function setupSupervisorWithSignature(User $user): UserSignature
    {
        Role::firstOrCreate(['name' => 'supervisor', 'guard_name' => 'web']);
        $supervisor = User::factory()->create([
            'agency_id' => $user->agency_id,
            'is_active' => true,
        ]);
        $supervisor->syncRoles(['supervisor']);

        Storage::disk('local')->put('user-signatures/test/firma.png', 'firma');

        return UserSignature::create([
            'user_id' => $supervisor->id,
            'agency_id' => $user->agency_id,
            'signature_path' => 'user-signatures/test/firma.png',
            'is_active' => true,
            'created_by' => $user->id,
        ]);
    }

    public function test_failed_pdf_keeps_error_record_and_consumes_folio(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $this->setupSupervisorWithSignature($user);
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

    public function test_successful_generation_persists_client_and_pdf_but_removes_temporary_files(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $this->setupSupervisorWithSignature($user);
        $widergy = Mockery::mock(WidergyDebtService::class);
        $widergy->shouldReceive('consult')->once()->andReturn($this->payload());
        $lookup = Mockery::mock(ClientExcelLookupService::class);
        $lookup->shouldReceive('findByClientNumber')->once()->andReturn(null);
        $qr = Mockery::mock(QrCodeService::class);
        $qr->shouldReceive('generate')->once()->andReturnUsing(function () {
            Storage::disk('local')->put('generated/temporary-qr.png', 'qr');

            return 'generated/temporary-qr.png';
        });
        $excel = Mockery::mock(PazSalvoExcelService::class);
        $excel->shouldReceive('generate')->once()->andReturnUsing(function () {
            Storage::disk('local')->put('generated/temporary.xlsx', 'xlsx');

            return 'generated/temporary.xlsx';
        });
        $pdf = Mockery::mock(PdfConversionService::class);
        $pdf->shouldReceive('convertXlsxToPdf')->once()->andReturnUsing(function () {
            Storage::disk('local')->put('generated/certificate.pdf', str_repeat('%PDF', 30));

            return 'generated/certificate.pdf';
        });

        $document = (new PazSalvoService($widergy, $lookup, app(CertificateNumberService::class), $qr, $excel, $pdf))->generate('34787', $user);

        $this->assertSame(PazSalvo::GENERATED, $document->status);
        $this->assertSame('generated/certificate.pdf', $document->pdf_path);
        $this->assertDatabaseHas('clients', ['client_number' => '34787', 'holder_name' => 'CLIENTE']);
        Storage::disk('local')->assertExists('generated/certificate.pdf');
        Storage::disk('local')->assertMissing('generated/temporary-qr.png');
        Storage::disk('local')->assertMissing('generated/temporary.xlsx');
    }

    private function payload(): array
    {
        return ['job' => ['job_id' => 'job'], 'result' => ['account' => ['client_number' => '34787', 'holder_name' => 'CLIENTE', 'address' => 'CALLE 1', 'city' => 'PANAMÁ', 'rate' => 'Residencial'], 'balances' => ['total_balance' => 0, 'expired_balance' => 0, 'non_expired_balance' => 0], 'debts' => [], 'raw' => []]];
    }
}
