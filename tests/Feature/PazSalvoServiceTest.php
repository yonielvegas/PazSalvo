<?php

namespace Tests\Feature;

use App\Exceptions\PdfConversionException;
use App\Models\GeneralAdminSignature;
use App\Models\PazSalvo;
use App\Models\User;
use App\Services\CertificateNumberService;
use App\Services\ClientExcelLookupService;
use App\Services\PazSalvoExcelService;
use App\Services\PazSalvoService;
use App\Services\PdfConversionService;
use App\Services\QrCodeService;
use App\Services\SanMiguelitoLocationService;
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

    public function test_generation_is_blocked_when_there_is_no_active_general_admin_signature(): void
    {
        $user = User::factory()->create();
        $widergy = Mockery::mock(WidergyDebtService::class);
        $widergy->shouldNotReceive('consult');
        $service = new PazSalvoService(
            $widergy,
            app(SanMiguelitoLocationService::class),
            Mockery::mock(ClientExcelLookupService::class),
            app(CertificateNumberService::class),
            Mockery::mock(QrCodeService::class),
            Mockery::mock(PazSalvoExcelService::class),
            Mockery::mock(PdfConversionService::class),
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('No hay un Administrador General activo con firma configurada.');
        $service->generate('34787', $user, '123456');
    }

    private function setupGeneralAdminWithSignature(): void
    {
        Role::firstOrCreate(['name' => 'administrador_general', 'guard_name' => 'web']);
        $generalAdmin = User::factory()->create(['is_active' => true]);
        $generalAdmin->syncRoles(['administrador_general']);

        Storage::disk('local')->put('general-admin-signatures/test/firma.png', 'firma');

        GeneralAdminSignature::create([
            'user_id' => $generalAdmin->id,
            'signature_path' => 'general-admin-signatures/test/firma.png',
            'is_active' => true,
            'created_by' => $generalAdmin->id,
        ]);
    }

    public function test_failed_pdf_keeps_error_record_and_consumes_folio(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $this->setupGeneralAdminWithSignature();
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
        $service = new PazSalvoService($widergy, app(SanMiguelitoLocationService::class), $lookup, app(CertificateNumberService::class), $qr, $excel, $pdf);

        try {
            $service->generate('34787', $user, '123456');
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
        $this->setupGeneralAdminWithSignature();
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

        $document = (new PazSalvoService($widergy, app(SanMiguelitoLocationService::class), $lookup, app(CertificateNumberService::class), $qr, $excel, $pdf))->generate('34787', $user, '123456');

        $this->assertSame(PazSalvo::GENERATED, $document->status);
        $this->assertSame('generated/certificate.pdf', $document->pdf_path);
        $this->assertDatabaseHas('clients', ['client_number' => '34787', 'holder_name' => 'CLIENTE']);
        Storage::disk('local')->assertExists('generated/certificate.pdf');
        Storage::disk('local')->assertMissing('generated/temporary-qr.png');
        Storage::disk('local')->assertMissing('generated/temporary.xlsx');
    }

    public function test_generation_blocked_when_city_not_san_miguelito(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $this->setupGeneralAdminWithSignature();
        $widergy = Mockery::mock(WidergyDebtService::class);
        $widergy->shouldReceive('consult')->once()->andReturn([
            'job' => ['job_id' => 'job'],
            'result' => ['account' => ['client_number' => '34787', 'holder_name' => 'CLIENTE', 'city' => 'PANAMA'], 'balances' => ['total_balance' => 0, 'aseo_balance' => 0, 'energy_balance' => 0], 'debts' => []],
        ]);
        $lookup = Mockery::mock(ClientExcelLookupService::class);
        $lookup->shouldNotReceive('findByClientNumber');
        $service = new PazSalvoService($widergy, app(SanMiguelitoLocationService::class), $lookup, app(CertificateNumberService::class), Mockery::mock(QrCodeService::class), Mockery::mock(PazSalvoExcelService::class), Mockery::mock(PdfConversionService::class));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('El cliente consultado no pertenece al distrito de San Miguelito.');
        $service->generate('34787', $user, '123456');
    }

    public function test_generation_blocked_when_city_is_null(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $this->setupGeneralAdminWithSignature();
        $widergy = Mockery::mock(WidergyDebtService::class);
        $widergy->shouldReceive('consult')->once()->andReturn([
            'job' => ['job_id' => 'job'],
            'result' => ['account' => ['client_number' => '34787', 'holder_name' => 'CLIENTE', 'city' => null], 'balances' => ['total_balance' => 0, 'aseo_balance' => 0, 'energy_balance' => 0], 'debts' => []],
        ]);
        $lookup = Mockery::mock(ClientExcelLookupService::class);
        $lookup->shouldNotReceive('findByClientNumber');
        $service = new PazSalvoService($widergy, app(SanMiguelitoLocationService::class), $lookup, app(CertificateNumberService::class), Mockery::mock(QrCodeService::class), Mockery::mock(PazSalvoExcelService::class), Mockery::mock(PdfConversionService::class));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('No se pudo confirmar que el cliente pertenece al distrito de San Miguelito.');
        $service->generate('34787', $user, '123456');
    }

    public function test_generation_allowed_when_city_is_san_miguelito_and_aseo_zero(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $this->setupGeneralAdminWithSignature();
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
        $document = (new PazSalvoService($widergy, app(SanMiguelitoLocationService::class), $lookup, app(CertificateNumberService::class), $qr, $excel, $pdf))->generate('34787', $user, '123456');
        $this->assertSame(PazSalvo::GENERATED, $document->status);
    }

    public function test_generation_allowed_when_san_miguelito_with_energy_debt(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $this->setupGeneralAdminWithSignature();
        $widergy = Mockery::mock(WidergyDebtService::class);
        $widergy->shouldReceive('consult')->once()->andReturn([
            'job' => ['job_id' => 'job'],
            'result' => ['account' => ['client_number' => '34787', 'holder_name' => 'CLIENTE', 'city' => 'BELISARIO FRIAS'], 'balances' => ['total_balance' => 20, 'aseo_balance' => 0, 'energy_balance' => 20], 'debts' => [['period' => '202606', 'amount' => 20, 'document_type' => 'Saldo de este mes Energía(JUN/2026)', 'status' => 'Pendiente']]],
        ]);
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
        $document = (new PazSalvoService($widergy, app(SanMiguelitoLocationService::class), $lookup, app(CertificateNumberService::class), $qr, $excel, $pdf))->generate('34787', $user, '123456');
        $this->assertSame(PazSalvo::GENERATED, $document->status);
    }

    private function payload(): array
    {
        return ['job' => ['job_id' => 'job'], 'result' => ['account' => ['client_number' => '34787', 'holder_name' => 'CLIENTE', 'address' => 'CALLE 1', 'city' => 'BELISARIO FRIAS', 'rate' => 'Residencial'], 'balances' => ['total_balance' => 0, 'expired_balance' => 0, 'non_expired_balance' => 0, 'aseo_balance' => 0, 'energy_balance' => 0, 'other_balance' => 0], 'debts' => [], 'raw' => []]];
    }
}
