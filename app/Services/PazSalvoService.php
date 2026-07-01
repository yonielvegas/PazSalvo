<?php

namespace App\Services;

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
        private readonly ClientExcelLookupService $lookup,
        private readonly CertificateNumberService $numbers,
        private readonly QrCodeService $qr,
        private readonly PazSalvoExcelService $excel,
        private readonly PdfConversionService $pdf,
    ) {}

    public function generate(string $clientNumber, User $user): PazSalvo
    {
        $user->loadMissing('agency');
        if (! $user->agency || ! $user->agency->is_active) {
            throw ValidationException::withMessages(['generation' => 'Debe tener una agencia activa asignada para emitir certificados.']);
        }

        $payload = $this->widergy->consult($clientNumber);
        $result = $payload['result'];
        $account = $result['account'] ?? [];
        $balances = $result['balances'] ?? [];
        if (! array_filter($account, fn ($v) => $v !== null && $v !== '')) {
            throw ValidationException::withMessages(['generation' => 'Widergy no devolvió información del cliente.']);
        }
        if ((float) ($balances['total_balance'] ?? 0) > 0) {
            throw ValidationException::withMessages(['generation' => 'El cliente tiene deuda en la reconsulta y ya no puede emitirse el certificado.']);
        }

        $master = $this->lookup->findByClientNumber($clientNumber);
        $holder = trim((string) ($master['holder_name'] ?? $account['holder_name'] ?? '')) ?: 'No especificado';
        $city = trim((string) ($master['corregimiento'] ?? $account['city'] ?? ''));
        $address = trim((string) ($master['address'] ?? $account['address'] ?? ''));
        $fullAddress = match (true) {
            $city !== '' && $address !== '' => $city.' - '.$address,
            $address !== '' => $address,
            $city !== '' => $city,
            default => 'Sin dirección',
        };
        $issuedAt = Carbon::now('America/Panama');
        $expiresAt = $issuedAt->copy()->addDays(30);
        $token = (string) Str::uuid();

        $record = DB::transaction(function () use ($user, $clientNumber, $payload, $result, $account, $balances, $master, $holder, $address, $fullAddress, $issuedAt, $expiresAt, $token) {
            $sequence = $this->numbers->reserve((int) $issuedAt->format('Y'));
            $snapshot = [
                'folio' => $sequence['folio'], 'verification_token' => $token,
                'client_number' => $clientNumber, 'holder_name' => $holder,
                'rate' => $account['rate'] ?? null, 'district' => $master['district'] ?? null,
                'corregimiento' => $master['corregimiento'] ?? null, 'city' => $account['city'] ?? null,
                'address' => $address, 'full_address' => $fullAddress,
                'balances' => $balances, 'debts' => $result['debts'] ?? [],
                'issued_at' => $issuedAt->toIso8601String(), 'expires_at' => $expiresAt->toIso8601String(),
                'generated_by' => $user->name, 'agency' => $user->agency->name,
                'authorized_by' => config('paz-salvo.authorized_by'), 'legal_text' => config('paz-salvo.legal_text'),
            ];

            return PazSalvo::create([
                'sequence_number' => $sequence['number'], 'sequence_year' => $sequence['year'], 'folio' => $sequence['folio'],
                'verification_token' => $token, 'generated_by' => $user->id, 'agency_id' => $user->agency->id,
                'client_number' => $clientNumber, 'holder_name' => $holder, 'rate' => $account['rate'] ?? null,
                'district' => $master['district'] ?? null, 'corregimiento' => $master['corregimiento'] ?? null,
                'city' => $account['city'] ?? null, 'address' => $address, 'full_address' => $fullAddress,
                'total_balance' => (float) ($balances['total_balance'] ?? 0),
                'expired_balance' => (float) ($balances['expired_balance'] ?? 0),
                'non_expired_balance' => (float) ($balances['non_expired_balance'] ?? 0),
                'issued_at' => $issuedAt, 'expires_at' => $expiresAt,
                'authorized_by_name' => config('paz-salvo.authorized_by'),
                'agency_name_snapshot' => $user->agency->name, 'generated_by_name_snapshot' => $user->name,
                'legal_text' => config('paz-salvo.legal_text'), 'status' => PazSalvo::PROCESSING,
                'raw_widergy_response' => $payload, 'certificate_snapshot' => $snapshot,
            ]);
        });

        $paths = [];
        try {
            $verificationUrl = route('public.certificates.verify', ['token' => $record->verification_token]);
            $paths[] = $qrPath = $this->qr->generate($verificationUrl, $record->folio);
            $documentData = array_merge($record->certificate_snapshot, [
                'issued_at' => $record->issued_at->timezone('America/Panama'),
                'expires_at' => $record->expires_at->timezone('America/Panama'),
                'authorized_by_name' => $record->authorized_by_name,
                'agency_name_snapshot' => $record->agency_name_snapshot,
                'generated_by_name_snapshot' => $record->generated_by_name_snapshot,
                'legal_text' => $record->legal_text,
            ]);
            $paths[] = $xlsxPath = $this->excel->generate($documentData, $qrPath);
            $paths[] = $pdfPath = $this->pdf->convertXlsxToPdf($xlsxPath);
            $disk = Storage::disk(config('paz-salvo.disk'));
            if (! $disk->exists($pdfPath) || $disk->size($pdfPath) < 100) {
                throw new \RuntimeException('El PDF generado no es válido.');
            }

            $record->update(['qr_path' => $qrPath, 'xlsx_path' => $xlsxPath, 'pdf_path' => $pdfPath, 'status' => PazSalvo::GENERATED]);

            return $record->fresh();
        } catch (\Throwable $e) {
            Storage::disk(config('paz-salvo.disk'))->delete($paths);
            $record->update(['status' => PazSalvo::ERROR, 'qr_path' => null, 'xlsx_path' => null, 'pdf_path' => null, 'generation_error' => Str::limit($e->getMessage(), 1000)]);
            throw $e;
        }
    }
}
