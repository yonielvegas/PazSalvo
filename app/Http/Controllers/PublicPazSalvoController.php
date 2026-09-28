<?php

namespace App\Http\Controllers;

use App\Services\PazSalvoPublicVerificationService;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PublicPazSalvoController extends Controller
{
    public function form(): View
    {
        return view('public.validate');
    }

    public function validateManual(Request $request, PazSalvoPublicVerificationService $service): View|RedirectResponse|Response
    {
        $this->rejectUnexpectedInputs($request, ['folio', 'fecha_emision']);

        $folio = $this->normalizeFolio($request->input('folio'));
        $fechaEmision = $this->normalizeDate($request->input('fecha_emision'));

        $validator = validator([
            'folio' => $folio,
            'fecha_emision' => $fechaEmision,
        ], [
            'folio' => ['bail', 'required', 'string', 'max:14', 'regex:/^CC-\d{6}-\d{4}$/'],
            'fecha_emision' => ['bail', 'required', 'string', 'max:10', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_string($value) || ! preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $value)) {
                    $fail('Ingrese la fecha de emisión con el formato dd/mm/aaaa.');

                    return;
                }

                try {
                    $date = Carbon::createFromFormat('d/m/Y', $value, 'America/Panama');
                } catch (\Throwable) {
                    $date = null;
                }

                if (! $date || $date->format('d/m/Y') !== $value) {
                    $fail('Ingrese una fecha de emisión real.');

                    return;
                }

                if ($date->isFuture()) {
                    $fail('Ingrese una fecha de emisión válida.');
                }
            }],
        ]);

        $validated = $validator->validate();

        try {
            $document = $service->findByFolioAndIssuedDate($validated['folio'], $validated['fecha_emision']);
            $certificate = $service->publicPayload($document);
        } catch (QueryException $exception) {
            return $this->databaseUnavailable($request, $exception, 'manual');
        }

        if (! $document) {
            return back()
                ->withInput()
                ->with('not_found_message', 'No se encontró un Paz y Salvo que coincida con los datos ingresados. Verifique el folio y la fecha de emisión.')
                ->with('not_found_id', (string) Str::uuid());
        }

        return view('public.result', [
            'certificate' => $certificate,
        ]);
    }

    public function verifyToken(Request $request, string $token, PazSalvoPublicVerificationService $service): View|Response
    {
        if (! $service->hasValidTokenFormat($token)) {
            return view('public.result', ['certificate' => null]);
        }

        try {
            $document = $service->findByToken($token);
            $certificate = $service->publicPayload($document);
        } catch (QueryException $exception) {
            return $this->databaseUnavailable($request, $exception, 'qr', $token);
        }

        return view('public.result', [
            'certificate' => $certificate,
        ]);
    }

    public function pdf(string $token, PazSalvoPublicVerificationService $service): Response|BinaryFileResponse
    {
        if (! $service->hasValidTokenFormat($token)) {
            abort(404);
        }

        try {
            $document = $service->findByToken($token);
        } catch (QueryException $exception) {
            return $this->databaseUnavailable(request(), $exception, 'pdf', $token);
        }

        if (! $document || $document->publicStatus() !== 'valid') {
            abort(404);
        }

        $pdfPath = $service->resolvePdfPath($document);

        if (! $pdfPath) {
            abort(404);
        }

        $filename = $service->safePdfFilename($document);

        return response()->file($pdfPath, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    private function rejectUnexpectedInputs(Request $request, array $allowed): void
    {
        $unexpected = array_diff(array_keys($request->except(['_token', '_method'])), $allowed);

        if ($unexpected !== []) {
            throw ValidationException::withMessages([
                'folio' => 'Los datos ingresados no tienen un formato válido.',
            ]);
        }
    }

    private function normalizeFolio(mixed $value): mixed
    {
        if (! is_scalar($value)) {
            return $value;
        }

        $value = strtoupper(trim(preg_replace('/\s+/', '', (string) $value) ?? ''));

        if (preg_match('/^\d{10}$/', $value)) {
            return 'CC-'.substr($value, 0, 6).'-'.substr($value, 6, 4);
        }

        return $value;
    }

    private function normalizeDate(mixed $value): mixed
    {
        if (! is_scalar($value)) {
            return $value;
        }

        return trim(preg_replace('/\s+/', '', (string) $value) ?? '');
    }

    private function databaseUnavailable(Request $request, QueryException $exception, string $flow, ?string $token = null): Response
    {
        Log::warning('Public Paz y Salvo database unavailable.', [
            'request_id' => $request->attributes->get('request_id'),
            'flow' => $flow,
            'token_hash' => $token ? substr(hash('sha256', $token), 0, 16) : null,
            'error_code' => $exception->getCode(),
        ]);

        return response()->view('errors.503', [], 503);
    }
}
