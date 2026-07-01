<?php

namespace App\Http\Controllers;

use App\Models\PazSalvo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PublicCertificateVerificationController extends Controller
{
    public function show(string $token): Response
    {
        $document = null;
        if (Str::isUuid($token)) {
            $document = PazSalvo::with('cancelledBy:id,name')->where('verification_token', $token)
                ->whereIn('status', [PazSalvo::GENERATED, PazSalvo::CANCELLED])->first();
        }

        return Inertia::render('public/verify', ['certificate' => $document ? [
            'status' => $document->publicStatus(), 'folio' => $document->folio, 'client_number' => $document->client_number,
            'holder_name' => $document->holder_name, 'full_address' => $document->full_address,
            'agency' => $document->agency_name_snapshot, 'generated_by' => $document->generated_by_name_snapshot,
            'issued_at' => $document->issued_at, 'expires_at' => $document->expires_at,
            'cancelled_at' => $document->cancelled_at, 'cancel_reason' => $document->cancel_reason,
            'pdf_url' => $document->status === PazSalvo::GENERATED ? route('public.certificates.pdf', $token) : null,
        ] : null]);
    }

    public function pdf(string $token): BinaryFileResponse
    {
        abort_unless(Str::isUuid($token), 404);
        $document = PazSalvo::where('verification_token', $token)->firstOrFail();
        abort_if($document->status === PazSalvo::CANCELLED, 403, 'Este certificado fue anulado.');
        abort_unless($document->status === PazSalvo::GENERATED && $document->pdf_path, 404);
        $disk = Storage::disk(config('paz-salvo.disk'));
        abort_unless($disk->exists($document->pdf_path), 404);

        return response()->file($disk->path($document->pdf_path), ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$document->folio.'.pdf"']);
    }
}
