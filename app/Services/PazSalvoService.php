<?php

namespace App\Services;

use App\Models\Client;
use App\Models\GeneralAdminSignature;
use App\Models\PazSalvo;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PazSalvoService
{
    public function __construct(
        private readonly WidergyDebtService $widergy,
        private readonly SanMiguelitoLocationService $sanMiguelito,
        private readonly ClientExcelLookupService $lookup,
        private readonly CertificateNumberService $numbers,
        private readonly QrCodeService $qr,
        private readonly PazSalvoExcelService $excel,
        private readonly PdfConversionService $pdf,
        private ?AuditLogger $audit = null,
    ) {}

    public function generate(string $clientNumber, User $user, string $numeroFactura): PazSalvo
    {
        $user->loadMissing('agency');
        if (! $user->agency || ! $user->agency->is_active) {
            throw ValidationException::withMessages(['generation' => 'Debe tener una agencia activa asignada para emitir certificados.']);
        }

        $generalAdminSignature = GeneralAdminSignature::where('is_active', true)
            ->with('user')
            ->first();

        if (! $generalAdminSignature || ! Storage::disk(config('paz-salvo.disk'))->exists($generalAdminSignature->signature_path)) {
            throw ValidationException::withMessages(['generation' => 'No hay un Administrador General activo con firma configurada.']);
        }

        $authorizedByName = $generalAdminSignature->user->name;
        $authorizedSignaturePath = $generalAdminSignature->signature_path;
        $generalAdminSignatureId = $generalAdminSignature->id;

        $payload = $this->widergy->consult($clientNumber);
        $result = $payload['result'];
        $account = $result['account'] ?? [];
        $balances = $result['balances'] ?? [];
        if (! array_filter($account, fn ($v) => $v !== null && $v !== '')) {
            throw ValidationException::withMessages(['generation' => 'Widergy no devolvió información del cliente.']);
        }
        $validation = $this->sanMiguelito->validate($account['city'] ?? null);
        if (! $validation['is_valid']) {
            throw ValidationException::withMessages(['generation' => $validation['message']]);
        }
        $aseoBalance = (float) ($balances['aseo_balance'] ?? 0);
        if ($aseoBalance > 0) {
            throw ValidationException::withMessages(['generation' => 'El cliente mantiene saldo pendiente de Aseo y no puede generar paz y salvo.']);
        }

        $master = $this->lookup->findByClientNumber($clientNumber);
        $holder = trim((string) ($master['holder_name'] ?? $account['holder_name'] ?? '')) ?: 'No especificado';
        $district = trim((string) ($master['district'] ?? '')) ?: null;
        $corregimiento = trim((string) ($master['corregimiento'] ?? '')) ?: null;
        $city = trim((string) ($account['city'] ?? '')) ?: null;
        $address = trim((string) ($master['address'] ?? $account['address'] ?? ''));
        $address = $address !== '' ? $address : null;
        $issuedAt = Carbon::now('America/Panama');
        $expiresAt = $issuedAt->copy()->addDays(30);
        $token = (string) Str::uuid();

        $record = DB::transaction(function () use ($user, $clientNumber, $numeroFactura, $account, $balances, $aseoBalance, $holder, $district, $corregimiento, $city, $address, $issuedAt, $expiresAt, $token, $generalAdminSignatureId) {
            $sequence = $this->numbers->reserve((int) $issuedAt->format('Y'));
            $client = Client::updateOrCreate(
                ['client_number' => $clientNumber],
                [
                    'holder_name' => $holder,
                    'rate' => $account['rate'] ?? null,
                    'district' => $district,
                    'corregimiento' => $corregimiento,
                    'city' => $city,
                    'address' => $address,
                ]
            );

            return PazSalvo::create([
                'sequence_number' => $sequence['number'], 'sequence_year' => $sequence['year'], 'folio' => $sequence['folio'],
                'verification_token' => $token, 'client_id' => $client->id, 'generated_by' => $user->id, 'agency_id' => $user->agency->id,
                'general_admin_signature_id' => $generalAdminSignatureId,
                'total_balance' => $aseoBalance,
                'numero_factura' => $numeroFactura,
                'issued_at' => $issuedAt, 'expires_at' => $expiresAt, 'status' => PazSalvo::PROCESSING,
            ]);
        });

        $temporaryPaths = [];
        $pdfPath = null;
        try {
            $record->load(['client', 'generatedBy', 'agency', 'generalAdminSignature.user']);
            $verificationUrl = route('public.certificates.verify', ['token' => $record->verification_token]);
            $temporaryPaths[] = $qrPath = $this->qr->generate($verificationUrl, $record->folio);
            $documentData = [
                'folio' => $record->folio,
                'verification_token' => $record->verification_token,
                'client_number' => $record->client->client_number,
                'holder_name' => $record->client->holder_name,
                'rate' => $record->client->rate,
                'full_address' => $record->client->full_address,
                'balance_total' => $record->total_balance,
                'issued_at' => $record->issued_at->timezone('America/Panama'),
                'expires_at' => $record->expires_at->timezone('America/Panama'),
                'agency_name' => $record->agency->name,
                'generated_by_name' => $record->generatedBy->name,
                'authorized_by_name' => $authorizedByName,
                'authorized_signature_path' => $authorizedSignaturePath,
                'legal_text' => config('paz-salvo.legal_text'),
            ];
            $temporaryPaths[] = $xlsxPath = $this->excel->generate($documentData, $qrPath);
            $pdfPath = $this->pdf->convertXlsxToPdf($xlsxPath);
            $disk = Storage::disk(config('paz-salvo.disk'));
            if (! $disk->exists($pdfPath) || $disk->size($pdfPath) < 100) {
                throw new \RuntimeException('El PDF generado no es válido.');
            }

            $record->update(['pdf_path' => $pdfPath, 'status' => PazSalvo::GENERATED]);
            $disk->delete($temporaryPaths);
            ($this->audit ??= app(AuditLogger::class))->record('paz_salvo.generated', [
                'folio' => $record->folio,
                'numero_factura' => $record->numero_factura,
                'agency_id' => $record->agency_id,
                'agency' => $record->agency->name,
                'generated_by' => $record->generated_by,
            ], $record);

            return $record->fresh(['client', 'generatedBy', 'agency', 'generalAdminSignature.user']);
        } catch (\Throwable $e) {
            Storage::disk(config('paz-salvo.disk'))->delete(array_filter([...$temporaryPaths, $pdfPath]));
            $record->update(['status' => PazSalvo::ERROR, 'pdf_path' => null, 'generation_error' => Str::limit($e->getMessage(), 1000)]);
            throw $e;
        }
    }
}
