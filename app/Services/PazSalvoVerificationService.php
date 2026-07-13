<?php

namespace App\Services;

use App\Models\PazSalvo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class PazSalvoVerificationService
{
    public function findByToken(string $token): ?PazSalvo
    {
        if (! Str::isUuid($token)) {
            return null;
        }

        return $this->baseQuery()
            ->where('verification_token', $token)
            ->first();
    }

    public function findByFolioAndIssuedDate(string $folio, string $issuedDate): ?PazSalvo
    {
        $date = Carbon::parse($issuedDate, 'America/Panama')->toDateString();

        return $this->baseQuery()
            ->where('folio', $folio)
            ->whereDate('issued_at', $date)
            ->first();
    }

    public function publicPayload(?PazSalvo $document, ?string $pdfToken = null): ?array
    {
        if (! $document) {
            return null;
        }

        return [
            'status' => $document->publicStatus(),
            'folio' => $document->folio,
            'client_number' => $document->client->client_number,
            'holder_name' => $document->client->holder_name,
            'full_address' => $document->client->full_address,
            'agency' => $document->agency->name,
            'generated_by' => $document->generatedBy->name,
            'authorized_by' => $document->generalAdminSignature?->user?->name,
            'issued_at' => $document->issued_at,
            'expires_at' => $document->expires_at,
            'cancelled_at' => $document->cancelled_at,
            'cancel_reason' => $document->cancel_reason,
            'pdf_url' => $pdfToken && $document->publicStatus() === 'valid'
                ? route('public.certificates.pdf', $pdfToken)
                : null,
        ];
    }

    private function baseQuery()
    {
        return PazSalvo::with([
            'client',
            'agency:id,name',
            'generatedBy:id,name',
            'generalAdminSignature.user:id,name',
            'cancelledBy:id,name',
        ])->whereIn('status', [PazSalvo::GENERATED, PazSalvo::CANCELLED]);
    }
}
