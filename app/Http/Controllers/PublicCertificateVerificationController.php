<?php

namespace App\Http\Controllers;

use App\Http\Requests\PublicPazSalvoValidationRequest;
use App\Models\PazSalvo;
use App\Services\AuditLogger;
use App\Services\PazSalvoVerificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PublicCertificateVerificationController extends Controller
{
    public function home(): Response
    {
        return Inertia::render('public/home');
    }

    public function manualForm(): Response
    {
        return Inertia::render('public/validate');
    }

    public function manualVerify(PublicPazSalvoValidationRequest $request, PazSalvoVerificationService $verification, AuditLogger $audit): Response|RedirectResponse
    {
        $data = $request->validated();
        $document = $verification->findByFolioAndIssuedDate($data['folio'], $data['fecha_emision']);
        $audit->record($document ? 'public_validation.succeeded' : 'public_validation.failed', [
            'folio' => $data['folio'],
            'fecha_emision' => $data['fecha_emision'],
        ], $document, $request);

        if (! $document) {
            return back()
                ->withInput()
                ->with('validation_not_found', 'No se encontró un Paz y Salvo con los datos ingresados. Revise el folio o la fecha de emisión.')
                ->with('validation_not_found_id', (string) Str::uuid());
        }

        return Inertia::render('public/verify', [
            'certificate' => $verification->publicPayload($document, $document->verification_token),
            'notFoundMessage' => null,
        ]);
    }

    public function show(string $token, PazSalvoVerificationService $verification): Response
    {
        $document = $verification->findByToken($token);

        return Inertia::render('public/verify', [
            'certificate' => $verification->publicPayload($document, $token),
            'notFoundMessage' => null,
        ]);
    }

    public function pdf(string $token): BinaryFileResponse
    {
        abort_unless(Str::isUuid($token), 404);
        $document = PazSalvo::where('verification_token', $token)->firstOrFail();
        abort_if($document->status === PazSalvo::CANCELLED, 403, 'Este certificado fue anulado.');
        abort_if($document->publicStatus() === 'expired', 403, 'Este Paz y Salvo se encuentra expirado y el documento ya no está disponible para visualización.');
        abort_unless($document->status === PazSalvo::GENERATED && $document->pdf_path, 404);
        $disk = Storage::disk(config('paz-salvo.disk'));
        abort_unless($disk->exists($document->pdf_path), 404);

        return response()->file($disk->path($document->pdf_path), ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$document->folio.'.pdf"']);
    }
}
