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
        $this->validateConfiguredUrl(config('widergy.complete_debts_url'));
        try {
            $response = $this->client()->get(config('widergy.complete_debts_url'), ['client_number' => $clientNumber]);
        } catch (\Throwable $e) {
            Log::warning('Widergy: complete_debts request failed', [
                'client_number' => $clientNumber,
                'error' => $e->getMessage(),
            ]);
            throw new WidergyException(
                'ENSA está caído o no responde en este momento. Intente nuevamente más tarde.',
                ['stage' => 'request_job', 'exception' => $e->getMessage()],
            );
        }

        if (! $response->successful()) {
            Log::warning('Widergy: complete_debts returned error status', [
                'client_number' => $clientNumber,
                'status' => $response->status(),
            ]);
            throw new WidergyException(
                'ENSA está caído o no responde en este momento. Intente nuevamente más tarde.',
                ['stage' => 'request_job', 'status' => $response->status(), 'body' => $response->json()],
            );
        }

        $payload = $response->json() ?? [];
        $jobId = $payload['job_id'] ?? $payload['response'] ?? null;
        if (! is_string($jobId) || $jobId === '') {
            Log::warning('Widergy: complete_debts response missing job_id', [
                'client_number' => $clientNumber,
                'body' => $payload,
            ]);
            throw new WidergyException(
                'ENSA está caído o no responde en este momento. Intente nuevamente más tarde.',
                ['stage' => 'request_job', 'body' => $payload],
            );
        }

        return ['job_id' => $jobId, 'url' => is_string($payload['url'] ?? null) ? $payload['url'] : null, 'raw' => $payload];
    }

    private function fetchJobResult(string $jobId, ?string $url): array
    {
        $endpoint = config('widergy.job_base_url');
        $this->validateConfiguredUrl($endpoint);
        for ($attempt = 1; $attempt <= config('widergy.poll_attempts'); $attempt++) {
            try {
                $response = $this->client()->get($endpoint, ['id' => $jobId]);
            } catch (\Throwable $e) {
                Log::warning('Widergy: fetch_job request failed', [
                    'job_id' => $jobId,
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                ]);
                throw new WidergyException(
                    'ENSA está caído o no responde en este momento. Intente nuevamente más tarde.',
                    ['stage' => 'fetch_job', 'attempt' => $attempt, 'exception' => $e->getMessage()],
                );
            }

            if (! $response->successful()) {
                Log::warning('Widergy: fetch_job returned error status', [
                    'job_id' => $jobId,
                    'attempt' => $attempt,
                    'status' => $response->status(),
                ]);
                throw new WidergyException(
                    'ENSA está caído o no responde en este momento. Intente nuevamente más tarde.',
                    ['stage' => 'fetch_job', 'status' => $response->status(), 'job_id' => $jobId],
                );
            }

            $payload = $response->json() ?? [];
            $candidate = $this->extractResult($payload);
            if (isset($candidate['account']) || isset($candidate['balances'])) {
                return $candidate;
            }

            $status = strtolower((string) ($payload['status'] ?? $payload['state'] ?? ''));
            if (in_array($status, ['failed', 'error', 'cancelled'], true)) {
                Log::warning('Widergy: job ended with error status', [
                    'job_id' => $jobId,
                    'status' => $status,
                ]);
                throw new WidergyException(
                    'ENSA está caído o no responde en este momento. Intente nuevamente más tarde.',
                    ['stage' => 'fetch_job', 'job_id' => $jobId, 'body' => $payload],
                );
            }

            if ($attempt < config('widergy.poll_attempts')) {
                usleep(config('widergy.poll_interval_ms') * 1000);
            }
        }

        Log::warning('Widergy: poll attempts exhausted', ['job_id' => $jobId, 'attempts' => config('widergy.poll_attempts')]);
        throw new WidergyException(
            'ENSA está caído o no responde en este momento. Intente nuevamente más tarde.',
            ['stage' => 'timeout', 'job_id' => $jobId, 'url' => $url],
        );
    }

    private function validateConfiguredUrl(string $url): void
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $allowedHosts = array_map('strtolower', config('widergy.allowed_hosts', []));

        if ($scheme !== 'https' || $host === '' || ! in_array($host, $allowedHosts, true)) {
            Log::error('Configuración insegura para la integración Widergy.', [
                'host' => $host ?: '[missing]',
                'scheme' => $scheme ?: '[missing]',
            ]);

            throw new WidergyException(
                'No se pudo completar la consulta. Intente nuevamente o contacte al administrador.',
                ['stage' => 'configuration'],
            );
        }
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
        $debts = is_array($payload['debts'] ?? null) ? array_values($payload['debts']) : [];

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
                ...$this->calculateServiceBalances($debts),
            ],
            'debts' => $debts,
            'next_expiration_on' => $payload['next_expiration_on'] ?? null,
            'raw' => $payload,
        ];
    }

    private function calculateServiceBalances(array $debts): array
    {
        $aseo = 0.0;
        $energy = 0.0;

        foreach ($debts as $debt) {
            $type = strtolower((string) ($debt['document_type'] ?? ''));
            $amount = (float) ($debt['amount'] ?? 0);

            if ($amount <= 0 || str_contains($type, 'total a pagar')) {
                continue;
            }

            if (str_contains($type, 'aseo')) {
                $aseo += $amount;
            } elseif (str_contains($type, 'energía') || str_contains($type, 'energia')) {
                $energy += $amount;
            }
        }

        return [
            'aseo_balance' => round($aseo, 2),
            'energy_balance' => round($energy, 2),
            'other_balance' => 0.0,
        ];
    }
}
