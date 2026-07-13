<?php

namespace App\Http\Controllers;

use App\Exceptions\ExcelLookupException;
use App\Exceptions\PdfConversionException;
use App\Exceptions\WidergyException;
use App\Http\Requests\ConsultPazSalvoRequest;
use App\Http\Requests\GeneratePazSalvoRequest;
use App\Models\PazSalvo;
use App\Services\PazSalvoService;
use App\Services\SanMiguelitoLocationService;
use App\Services\WidergyDebtService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PazSalvoController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('paz-salvo/index');
    }

    public function consult(ConsultPazSalvoRequest $request, WidergyDebtService $widergy, SanMiguelitoLocationService $sanMiguelito): RedirectResponse
    {
        $clientNumber = $request->validated('client_number');
        $request->session()->forget(['paz_salvo_query', 'paz_salvo_result', 'document']);
        try {
            $payload = $widergy->consult($clientNumber);
        } catch (WidergyException $e) {
            Log::warning('Widergy debt query failed', [
                'client_number' => $clientNumber,
                'context' => $e->context,
            ]);

            return back()->with('error', $e->getMessage())->withErrors(['client_number' => $e->getMessage()]);
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'No se pudo completar la consulta. Intente nuevamente.')->withErrors(['client_number' => 'No se pudo completar la consulta. Intente nuevamente.']);
        }

        $result = $payload['result'];
        $account = $result['account'] ?? [];
        $hasAccount = count(array_filter($account, fn ($v) => $v !== null && $v !== '')) > 0;

        if (! $hasAccount) {
            $token = (string) Str::uuid();
            $query = $this->queryPayload($clientNumber, $result, 'not_found', $token, false, false);
            $request->session()->put('paz_salvo_query', [
                'token' => $token, 'client_number' => $clientNumber,
                'expires_at' => now()->addMinutes(config('paz-salvo.query_ttl_minutes'))->timestamp,
            ]);
            $request->session()->put('paz_salvo_result', $query);

            return back()->with('result', $query);
        }

        $validation = $sanMiguelito->validate($account['city'] ?? null);

        if (! $validation['is_valid']) {
            $token = (string) Str::uuid();
            $query = $this->queryPayload($clientNumber, $result, 'not_san_miguelito', $token, false, false, $validation);
            $request->session()->put('paz_salvo_query', [
                'token' => $token, 'client_number' => $clientNumber,
                'expires_at' => now()->addMinutes(config('paz-salvo.query_ttl_minutes'))->timestamp,
            ]);
            $request->session()->put('paz_salvo_result', $query);

            return back()->with('result', $query);
        }

        $aseoBalance = (float) ($result['balances']['aseo_balance'] ?? 0);
        $energyBalance = (float) ($result['balances']['energy_balance'] ?? 0);

        if ($aseoBalance > 0) {
            $status = 'has_aseo_debt';
            $canGeneratePazSalvo = false;
            $requiresEnergyWarning = false;
        } elseif ($energyBalance > 0) {
            $status = 'debt_free_aseo_with_energy_debt';
            $canGeneratePazSalvo = true;
            $requiresEnergyWarning = true;
        } else {
            $status = 'debt_free';
            $canGeneratePazSalvo = true;
            $requiresEnergyWarning = false;
        }

        Log::info('Paz salvo validation summary', [
            'client_number' => $clientNumber,
            'city' => $account['city'] ?? null,
            'is_san_miguelito' => true,
            'aseo_balance' => $aseoBalance,
            'energy_balance' => $energyBalance,
            'status' => $status,
        ]);

        $token = (string) Str::uuid();
        $query = $this->queryPayload($clientNumber, $result, $status, $token, $canGeneratePazSalvo, $requiresEnergyWarning);
        $request->session()->put('paz_salvo_query', [
            'token' => $token, 'client_number' => $clientNumber,
            'expires_at' => now()->addMinutes(config('paz-salvo.query_ttl_minutes'))->timestamp,
        ]);
        $request->session()->put('paz_salvo_result', $query);

        return back()->with('result', $query);
    }

    public function generate(GeneratePazSalvoRequest $request, PazSalvoService $service): RedirectResponse
    {
        $query = $request->session()->get('paz_salvo_query');
        if (! is_array($query) || ! hash_equals((string) ($query['token'] ?? ''), $request->validated('query_token')) || now()->timestamp > ($query['expires_at'] ?? 0)) {
            return $this->backToGenerationResult($request, ['generation' => 'La consulta expiró o no corresponde a esta sesión. Consulte nuevamente.']);
        }
        try {
            $document = $service->generate($query['client_number'], $request->user(), $request->validated('numero_factura'));
        } catch (WidergyException|ExcelLookupException|PdfConversionException|ValidationException $e) {
            if ($e instanceof ValidationException) {
                return $this->backToGenerationResult($request, $e->errors());
            }

            return $this->backToGenerationResult($request, ['generation' => $e->getMessage()]);
        } catch (\Throwable $e) {
            report($e);

            return $this->backToGenerationResult($request, ['generation' => 'No se pudo generar el certificado. El folio reservado quedó registrado como error.']);
        }
        $request->session()->forget(['paz_salvo_query', 'paz_salvo_result']);

        return redirect()->route('paz-salvos.show', $document)->with('message', 'Certificado generado correctamente.');
    }

    public function showPdf(PazSalvo $pazSalvo): BinaryFileResponse
    {
        return response()->file($this->pdfPath($pazSalvo), ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$pazSalvo->folio.'.pdf"']);
    }

    public function downloadPdf(PazSalvo $pazSalvo): BinaryFileResponse
    {
        return response()->download($this->pdfPath($pazSalvo), $pazSalvo->folio.'.pdf', ['Content-Type' => 'application/pdf']);
    }

    private function pdfPath(PazSalvo $document): string
    {
        abort_unless(in_array($document->status, [PazSalvo::GENERATED, PazSalvo::CANCELLED], true) && $document->pdf_path, 404);
        $disk = Storage::disk(config('paz-salvo.disk'));
        abort_unless($disk->exists($document->pdf_path), 404);

        return $disk->path($document->pdf_path);
    }

    private function backToGenerationResult(GeneratePazSalvoRequest $request, array $errors): RedirectResponse
    {
        return back()
            ->withErrors($errors)
            ->withInput()
            ->with('result', $request->session()->get('paz_salvo_result'));
    }

    private function queryPayload(string $clientNumber, array $result, string $status, string $token, bool $canGeneratePazSalvo, bool $requiresEnergyWarning, ?array $validation = null): array
    {
        $payload = [
            'query_token' => $token, 'status' => $status, 'client_number' => $clientNumber,
            'holder_name' => $result['account']['holder_name'] ?? null, 'address' => $result['account']['address'] ?? null,
            'city' => $result['account']['city'] ?? null, 'rate' => $result['account']['rate'] ?? null,
            'balances' => $result['balances'], 'debts' => collect($result['debts'] ?? [])->map(fn ($i) => [
                'period' => $i['period'] ?? $i['billing_period'] ?? null, 'amount' => (float) ($i['amount'] ?? $i['balance'] ?? 0),
                'document_type' => $i['document_type'] ?? $i['type'] ?? null, 'status' => $i['status'] ?? null,
            ])->values(),
            'can_generate_paz_salvo' => $canGeneratePazSalvo,
            'requires_energy_warning' => $requiresEnergyWarning,
        ];

        if ($status === 'not_san_miguelito' && $validation !== null) {
            $payload['san_miguelito_validation'] = [
                'is_san_miguelito' => false,
                'received_city' => $validation['received_city'],
                'message' => $validation['message'],
            ];
        }

        return $payload;
    }
}
