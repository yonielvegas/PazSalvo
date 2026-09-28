<?php

namespace App\Services;

use App\Models\PazSalvo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PazSalvoPublicVerificationService
{
    public function hasValidTokenFormat(string $token): bool
    {
        return strlen($token) <= 36 && Str::isUuid($token);
    }

    public function findByToken(string $token): ?PazSalvo
    {
        if (! $this->hasValidTokenFormat($token)) {
            return null;
        }

        return $this->baseQuery()
            ->where('verification_token', $token)
            ->first();
    }

    public function findByFolioAndIssuedDate(string $folio, string $fechaEmision): ?PazSalvo
    {
        if (! preg_match('/^CC-\d{6}-\d{4}$/', $folio)) {
            return null;
        }

        try {
            $date = Carbon::createFromFormat('d/m/Y', $fechaEmision, 'America/Panama');
        } catch (\Throwable) {
            return null;
        }

        if (! $date || $date->format('d/m/Y') !== $fechaEmision) {
            return null;
        }

        $start = $date->copy()->startOfDay();
        $end = $date->copy()->addDay()->startOfDay();

        return $this->baseQuery()
            ->where('folio', $folio)
            ->where('issued_at', '>=', $start->toIso8601String())
            ->where('issued_at', '<', $end->toIso8601String())
            ->first();
    }

    public function publicPayload(?PazSalvo $document): ?array
    {
        if (! $document) {
            return null;
        }

        return [
            'status' => $document->publicStatus(),
            'folio' => $document->folio,
            'client_number' => $this->maskClientNumber($document->client?->client_number),
            'holder_name' => $document->client?->holder_name,
            'agency' => $document->agency?->name,
            'issued_at' => $document->issued_at,
            'expires_at' => $document->expires_at,
            'cancelled_at' => $document->cancelled_at,
            'pdf_url' => $document->publicStatus() === 'valid' && $this->hasAvailablePdf($document)
                ? route('public.verify.pdf', ['token' => $document->verification_token])
                : null,
        ];
    }

    public function hasAvailablePdf(PazSalvo $document): bool
    {
        return $this->resolvePdfPath($document) !== null;
    }

    public function resolvePdfPath(PazSalvo $document): ?string
    {
        $relativePath = $this->normalizePdfPath((string) $document->pdf_path);

        if ($relativePath === null) {
            $this->logPdfUnavailable($document, 'invalid_path');

            return null;
        }

        $root = trim((string) config('paz-salvo.output_dir'), '/');
        if ($root === '' || ! str_starts_with($relativePath, $root.'/')) {
            $this->logPdfUnavailable($document, 'outside_allowed_root');

            return null;
        }

        $path = $relativePath;
        $disk = Storage::disk((string) config('paz-salvo.disk'));

        if (! $disk->exists($path)) {
            $this->logPdfUnavailable($document, 'missing_file');

            return null;
        }

        $absolutePath = $disk->path($path);
        $absoluteRoot = realpath($disk->path($root === '' ? '.' : $root));
        $resolved = realpath($absolutePath);

        if (
            ! $absoluteRoot
            || ! $resolved
            || ! str_starts_with($resolved, $absoluteRoot.DIRECTORY_SEPARATOR)
            || ! is_file($resolved)
        ) {
            $this->logPdfUnavailable($document, 'outside_allowed_root');

            return null;
        }

        if (mime_content_type($resolved) !== 'application/pdf') {
            $this->logPdfUnavailable($document, 'invalid_mime');

            return null;
        }

        return $resolved;
    }

    public function safePdfFilename(PazSalvo $document): string
    {
        $folio = preg_replace('/[^A-Z0-9-]/', '', strtoupper((string) $document->folio)) ?: 'paz-salvo';

        return $folio.'.pdf';
    }

    private function baseQuery(): Builder
    {
        return PazSalvo::query()
            ->select(['id', 'client_id', 'agency_id', 'verification_token', 'folio', 'status', 'issued_at', 'expires_at', 'cancelled_at', 'pdf_path'])
            ->with(['client:id,client_number,holder_name', 'agency:id,name'])
            ->whereIn('status', [PazSalvo::GENERATED, PazSalvo::CANCELLED]);
    }

    private function maskClientNumber(?string $clientNumber): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $clientNumber);

        if ($digits === '') {
            return null;
        }

        return str_repeat('*', max(0, strlen($digits) - 4)).substr($digits, -4);
    }

    private function normalizePdfPath(string $path): ?string
    {
        $path = trim(str_replace('\\', '/', $path));

        if ($path === '' || str_starts_with($path, '/')) {
            return null;
        }

        $segments = array_values(array_filter(explode('/', $path), fn (string $segment): bool => $segment !== ''));

        if ($segments === [] || in_array('..', $segments, true)) {
            return null;
        }

        return implode('/', $segments);
    }

    private function logPdfUnavailable(PazSalvo $document, string $reason): void
    {
        Log::warning('Public Paz y Salvo PDF unavailable.', [
            'request_id' => request()->attributes->get('request_id'),
            'folio' => $document->folio,
            'token_hash' => $document->verification_token ? substr(hash('sha256', (string) $document->verification_token), 0, 16) : null,
            'pdf_path_hash' => $document->pdf_path ? substr(hash('sha256', (string) $document->pdf_path), 0, 16) : null,
            'reason' => $reason,
        ]);
    }
}
