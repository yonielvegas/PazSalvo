<?php

namespace App\Services;

use App\Exceptions\WidergyException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WidergyDebtService
{
    public function consult(string $clientNumber): array
    {
        $job = $this->requestJob($clientNumber);
        $result = $this->fetchJobResult($job['job_id'], $job['url'] ?? null);

        return ['job' => $job, 'result' => $this->normalize($result)];
    }

    private function client(): PendingRequest
    {
        $utilityId = trim((string) config('widergy.utility_id'));
        $channel = trim((string) config('widergy.channel'));

        if ($utilityId === '' || $channel === '') {
            $missing = array_keys(array_filter([
                'WIDERGY_UTILITY_ID' => $utilityId === '',
                'WIDERGY_CHANNEL' => $channel === '',
            ]));

            Log::error('Configuración incompleta para la integración Widergy.', [
                'missing_environment_variables' => $missing,
            ]);

            throw new WidergyException(
                'No se pudo completar la consulta. Intente nuevamente o contacte al administrador.',
                ['stage' => 'configuration', 'missing' => $missing],
            );
        }

        return Http::withHeaders([
            'Accept' => 'application/json',
            'Utility-ID' => $utilityId,
            'channel' => $channel,
        ])
            ->connectTimeout(config('widergy.connect_timeout'))
            ->timeout(config('widergy.request_timeout'))
            ->retry(2, 250, throw: false);
    }

    private function requestJob(string $clientNumber): array
    {
        $response = $this->client()->get(config('widergy.complete_debts_url'), ['client_number' => $clientNumber]);
        if (! $response->successful()) {
            throw new WidergyException('No se pudo iniciar la consulta del cliente.', ['stage' => 'request_job', 'status' => $response->status(), 'body' => $response->json()]);
        }

        $payload = $response->json() ?? [];
        $jobId = $payload['job_id'] ?? $payload['response'] ?? null;
        if (! is_string($jobId) || $jobId === '') {
            throw new WidergyException('Widergy no devolvió un identificador de consulta.', ['stage' => 'request_job', 'body' => $payload]);
        }

        return ['job_id' => $jobId, 'url' => is_string($payload['url'] ?? null) ? $payload['url'] : null, 'raw' => $payload];
    }

    private function fetchJobResult(string $jobId, ?string $url): array
    {
        $endpoint = config('widergy.job_base_url');
        for ($attempt = 1; $attempt <= config('widergy.poll_attempts'); $attempt++) {
            $response = $this->client()->get($endpoint, ['id' => $jobId]);
            if (! $response->successful()) {
                throw new WidergyException('No se pudo obtener el resultado de la consulta.', ['stage' => 'fetch_job', 'status' => $response->status(), 'job_id' => $jobId]);
            }

            $payload = $response->json() ?? [];
            $candidate = $this->extractResult($payload);
            if (isset($candidate['account']) || isset($candidate['balances'])) {
                return $candidate;
            }

            $status = strtolower((string) ($payload['status'] ?? $payload['state'] ?? ''));
            if (in_array($status, ['failed', 'error', 'cancelled'], true)) {
                throw new WidergyException('La consulta externa terminó con error.', ['stage' => 'fetch_job', 'job_id' => $jobId, 'body' => $payload]);
            }

            if ($attempt < config('widergy.poll_attempts')) {
                usleep(config('widergy.poll_interval_ms') * 1000);
            }
        }

        throw new WidergyException('La consulta tardó más de lo esperado.', ['stage' => 'timeout', 'job_id' => $jobId, 'url' => $url]);
    }

    private function extractResult(array $payload): array
    {
        foreach (['result', 'response', 'data'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                return $payload[$key];
            }
        }

        return $payload;
    }

    private function normalize(array $payload): array
    {
        $account = is_array($payload['account'] ?? null) ? $payload['account'] : [];
        $balances = is_array($payload['balances'] ?? null) ? $payload['balances'] : [];

        return [
            'account' => [
                'client_number' => isset($account['client_number']) ? (string) $account['client_number'] : null,
                'holder_name' => $account['holder_name'] ?? null,
                'address' => $account['address'] ?? null,
                'city' => $account['city'] ?? null,
                'rate' => $account['rate'] ?? null,
            ],
            'balances' => [
                'total_balance' => (float) ($balances['total_balance'] ?? 0),
                'expired_balance' => (float) ($balances['expired_balance'] ?? 0),
                'non_expired_balance' => (float) ($balances['non_expired_balance'] ?? 0),
            ],
            'debts' => is_array($payload['debts'] ?? null) ? array_values($payload['debts']) : [],
            'next_expiration_on' => $payload['next_expiration_on'] ?? null,
            'raw' => $payload,
        ];
    }
}
