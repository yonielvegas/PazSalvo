<?php

namespace App\Services;

use App\Models\Client;
use App\Models\PazSalvo;
use App\Models\User;
use App\Models\UserSignature;
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

        $activeSignature = UserSignature::where('agency_id', $user->agency_id)
            ->where('is_active', true)
            ->with('user')
            ->first();

        if (! $activeSignature || ! Storage::disk(config('paz-salvo.disk'))->exists($activeSignature->signature_path)) {
            throw ValidationException::withMessages(['generation' => 'No hay un jefe de agencia activo con firma configurada para esta agencia.']);
        }

        $authorizedByName = $activeSignature->user->name;
        $signaturePath = $activeSignature->signature_path;
        $userSignatureId = $activeSignature->id;

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
        $district = trim((string) ($master['district'] ?? '')) ?: null;
        $corregimiento = trim((string) ($master['corregimiento'] ?? '')) ?: null;
        $city = trim((string) ($account['city'] ?? '')) ?: null;
        $address = trim((string) ($master['address'] ?? $account['address'] ?? ''));
        $address = $address !== '' ? $address : null;
        $issuedAt = Carbon::now('America/Panama');
        $expiresAt = $issuedAt->copy()->addDays(30);
        $token = (string) Str::uuid();

        $record = DB::transaction(function () use ($user, $clientNumber, $account, $balances, $holder, $district, $corregimiento, $city, $address, $issuedAt, $expiresAt, $token, $userSignatureId) {
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
                'user_signature_id' => $userSignatureId,
                'total_balance' => (float) ($balances['total_balance'] ?? 0),
                'issued_at' => $issuedAt, 'expires_at' => $expiresAt, 'status' => PazSalvo::PROCESSING,
            ]);
        });

        $temporaryPaths = [];
        $pdfPath = null;
        try {
            $record->load(['client', 'generatedBy', 'agency', 'userSignature.user']);
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
                'authorized_by_name' => $authorizedByName,
                'agency_name' => $record->agency->name,
                'generated_by_name' => $record->generatedBy->name,
                'signature_path' => $signaturePath,
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

            return $record->fresh(['client', 'generatedBy', 'agency', 'userSignature.user']);
        } catch (\Throwable $e) {
            Storage::disk(config('paz-salvo.disk'))->delete(array_filter([...$temporaryPaths, $pdfPath]));
            $record->update(['status' => PazSalvo::ERROR, 'pdf_path' => null, 'generation_error' => Str::limit($e->getMessage(), 1000)]);
            throw $e;
        }
    }
}
