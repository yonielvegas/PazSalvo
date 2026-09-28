<?php

namespace Tests\Feature;

use App\Exceptions\PdfConversionException;
use App\Models\Client;
use App\Models\GeneralAdminSignature;
use App\Models\PazSalvo;
use App\Models\User;
use App\Services\AuditLogger;
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
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function test_failed_pdf_rolls_back_record_client_and_folio_and_can_be_retried(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $this->setupGeneralAdminWithSignature();
        $widergy = Mockery::mock(WidergyDebtService::class);
        $widergy->shouldReceive('consult')->twice()->andReturn($this->payload());
        $lookup = Mockery::mock(ClientExcelLookupService::class);
        $lookup->shouldReceive('findByClientNumber')->twice()->andReturn(null);
        $qr = Mockery::mock(QrCodeService::class);
        $qr->shouldReceive('generate')->twice()->andReturnUsing(function () {
            Storage::disk('local')->put('generated/qr.png', 'qr');

            return 'generated/qr.png';
        });
        $excel = Mockery::mock(PazSalvoExcelService::class);
        $excel->shouldReceive('generate')->twice()->andReturnUsing(function () {
            Storage::disk('local')->put('generated/test.xlsx', 'xlsx');

            return 'generated/test.xlsx';
        });
        $pdf = Mockery::mock(PdfConversionService::class);
        $pdf->shouldReceive('convertXlsxToPdf')->once()->andReturnUsing(function () {
            Storage::disk('local')->put('generated/test.pdf', '%PDF-partial');
            throw new PdfConversionException('LibreOffice falló');
        });
        $pdf->shouldReceive('convertXlsxToPdf')->once()->andReturnUsing(function () {
            Storage::disk('local')->put('generated/test.pdf', str_repeat('%PDF', 30));

            return 'generated/test.pdf';
        });
        $service = new PazSalvoService($widergy, app(SanMiguelitoLocationService::class), $lookup, app(CertificateNumberService::class), $qr, $excel, $pdf);

        try {
            $service->generate('34787', $user, '123456', '00000000-0000-4000-8000-000000000001');
            $this->fail('Expected exception');
        } catch (PdfConversionException) {
        }

        $this->assertDatabaseCount('paz_salvos', 0);
        $this->assertDatabaseCount('clients', 0);
        $this->assertDatabaseCount('certificate_sequences', 0);
        $this->assertDatabaseMissing('paz_salvos', ['status' => PazSalvo::ERROR]);
        Storage::disk('local')->assertMissing('generated/qr.png');
        Storage::disk('local')->assertMissing('generated/test.xlsx');
        Storage::disk('local')->assertMissing('generated/test.pdf');
        $document = $service->generate('34787', $user, '123456', '00000000-0000-4000-8000-000000000001');
        $this->assertSame(sprintf('CC-000001-%d', now('America/Panama')->year), $document->folio);
        $this->assertSame(PazSalvo::GENERATED, $document->status);
        $this->assertDatabaseCount('paz_salvos', 1);
        Storage::disk('local')->assertExists('generated/test.pdf');
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

    public static function failureStages(): array
    {
        return [['url'], ['qr'], ['xlsx'], ['invalid_pdf'], ['audit']];
    }

    #[DataProvider('failureStages')]
    public function test_any_generation_failure_restores_existing_client_sequence_and_files(string $stage): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $this->setupGeneralAdminWithSignature();
        $client = Client::factory()->create(['client_number' => '34787', 'holder_name' => 'ORIGINAL']);
        \DB::table('certificate_sequences')->insert(['year' => now('America/Panama')->year, 'last_number' => 10]);
        $widergy = Mockery::mock(WidergyDebtService::class);
        $widergy->shouldReceive('consult')->once()->andReturn($this->payload());
        $lookup = Mockery::mock(ClientExcelLookupService::class);
        $lookup->shouldReceive('findByClientNumber')->once()->andReturn(null);
        $qr = Mockery::mock(QrCodeService::class);
        $excel = Mockery::mock(PazSalvoExcelService::class);
        $pdf = Mockery::mock(PdfConversionService::class);
        $audit = Mockery::mock(AuditLogger::class);
        if ($stage === 'url') {
            config(['paz_salvo.public_verification_base_url' => '']);
        } else {
            $qr->shouldReceive('generate')->once()->andReturnUsing(function () use ($stage) {
                if ($stage === 'qr') {
                    throw new \RuntimeException('QR failure');
                }
                Storage::disk('local')->put('generated/temp.png', 'qr');

                return 'generated/temp.png';
            });
        }
        if (! in_array($stage, ['url', 'qr'], true)) {
            $excel->shouldReceive('generate')->once()->andReturnUsing(function () use ($stage) {
                if ($stage === 'xlsx') {
                    throw new \RuntimeException('XLSX failure');
                }
                Storage::disk('local')->put('generated/temp.xlsx', 'xlsx');

                return 'generated/temp.xlsx';
            });
        }
        if (in_array($stage, ['invalid_pdf', 'audit'], true)) {
            $pdf->shouldReceive('convertXlsxToPdf')->once()->andReturnUsing(function () use ($stage) {
                Storage::disk('local')->put('generated/temp.pdf', $stage === 'invalid_pdf' ? '%PDF-small' : str_repeat('%PDF', 30));

                return 'generated/temp.pdf';
            });
        }
        if ($stage === 'audit') {
            $audit->shouldReceive('record')->once()->andThrow(new \RuntimeException('Audit failed after certificate insertion'));
        }
        $service = new PazSalvoService($widergy, app(SanMiguelitoLocationService::class), $lookup, app(CertificateNumberService::class), $qr, $excel, $pdf, $audit);
        try {
            $service->generate('34787', $user, '123456');
            $this->fail('Generation must fail.');
        } catch (\InvalidArgumentException|\RuntimeException) {
        }
        $this->assertDatabaseCount('paz_salvos', 0);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertSame('ORIGINAL', $client->fresh()->holder_name);
        $this->assertSame(10, \DB::table('certificate_sequences')->value('last_number'));
        foreach (['generated/temp.png', 'generated/temp.xlsx', 'generated/temp.pdf'] as $path) {
            Storage::disk('local')->assertMissing($path);
        }
    }

    public function test_generation_uses_same_domain_qr_and_idempotency_does_not_reserve_again(): void
    {
        Storage::fake('local');
        config(['app.env' => 'production', 'paz_salvo.public_verification_base_url' => 'http://pazysalvo.aaud.gob.pa/verificar']);
        $user = User::factory()->create();
        $this->setupGeneralAdminWithSignature();
        $widergy = Mockery::mock(WidergyDebtService::class);
        $widergy->shouldReceive('consult')->twice()->andReturn($this->payload());
        $lookup = Mockery::mock(ClientExcelLookupService::class);
        $lookup->shouldReceive('findByClientNumber')->twice()->andReturn(null);
        $qr = Mockery::mock(QrCodeService::class);
        $qr->shouldReceive('generate')->twice()->with(Mockery::on(fn ($url) => str_starts_with($url, 'http://pazysalvo.aaud.gob.pa/verificar/') && Str::isUuid(basename($url))), Mockery::type('string'))->andReturnUsing(function () {
            Storage::disk('local')->put('generated/temp.png', 'qr');

            return 'generated/temp.png';
        });
        $excel = Mockery::mock(PazSalvoExcelService::class);
        $excel->shouldReceive('generate')->twice()->andReturnUsing(function ($data) {
            $path = 'generated/'.$data['folio'].'.xlsx';
            Storage::disk('local')->put($path, 'xlsx');

            return $path;
        });
        $pdf = Mockery::mock(PdfConversionService::class);
        $pdf->shouldReceive('convertXlsxToPdf')->twice()->andReturnUsing(function ($xlsx) {
            $path = str_replace('.xlsx', '.pdf', $xlsx);
            Storage::disk('local')->put($path, str_repeat('%PDF', 30));

            return $path;
        });
        $service = new PazSalvoService($widergy, app(SanMiguelitoLocationService::class), $lookup, app(CertificateNumberService::class), $qr, $excel, $pdf);
        $requestId = (string) Str::uuid();
        $first = $service->generate('34787', $user, '123456', $requestId);
        $reused = $service->generate('34787', $user, '123456', $requestId);
        $second = $service->generate('34787', $user, '654321', (string) Str::uuid());
        $this->assertSame($first->id, $reused->id);
        $this->assertSame(1, $first->sequence_number);
        $this->assertSame(2, $second->sequence_number);
        $this->assertDatabaseCount('paz_salvos', 2);
        $this->assertSame(2, \DB::table('certificate_sequences')->value('last_number'));
        Storage::disk('local')->assertExists($first->pdf_path);
        Storage::disk('local')->assertExists($second->pdf_path);
    }
}
