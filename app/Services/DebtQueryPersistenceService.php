<?php

namespace App\Services;

use App\Models\Client;
use App\Models\DebtQuery;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DebtQueryPersistenceService
{
    public function storeSuccess(string $clientNumber, array $job, array $result): DebtQuery
    {
        return DB::transaction(function () use ($clientNumber, $job, $result) {
            $account = $result['account'] ?? [];
            $client = $this->upsertClient($clientNumber, $account);
            $balances = $result['balances'] ?? [];
            $total = (float) ($balances['total_balance'] ?? 0);
            $hasAccount = count(array_filter($account, fn ($value) => $value !== null && $value !== '')) > 0;

            $query = DebtQuery::create([
                'client_id' => $client->id,
                'client_number' => $clientNumber,
                'job_id' => $job['job_id'] ?? null,
                'job_url' => $job['url'] ?? null,
                'status' => $hasAccount ? ($total > 0 ? DebtQuery::HAS_DEBT : DebtQuery::DEBT_FREE) : DebtQuery::NOT_FOUND,
                'total_balance' => $total,
                'expired_balance' => (float) ($balances['expired_balance'] ?? 0),
                'non_expired_balance' => (float) ($balances['non_expired_balance'] ?? 0),
                'external_holder_name' => $account['holder_name'] ?? null,
                'external_address' => $account['address'] ?? null,
                'external_city' => $account['city'] ?? null,
                'external_rate' => $account['rate'] ?? null,
                'next_expiration_on' => $this->dateOrNull($result['next_expiration_on'] ?? null),
                'raw_response' => ['job' => $job['raw'] ?? $job, 'result' => $result['raw'] ?? $result],
                'queried_at' => now(),
            ]);

            foreach ($result['debts'] ?? [] as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $query->items()->create([
                    'period' => $this->first($item, ['period', 'billing_period', 'description']),
                    'external_id' => $this->first($item, ['external_id', 'id', 'debt_id']),
                    'amount' => (float) ($this->first($item, ['amount', 'balance', 'total']) ?? 0),
                    'status' => $item['status'] ?? null,
                    'payable' => Arr::has($item, 'payable') ? (bool) $item['payable'] : null,
                    'document_type' => $this->first($item, ['document_type', 'type']),
                    'issued_on' => $this->dateOrNull($this->first($item, ['issued_on', 'issue_date'])),
                    'first_expiration_on' => $this->dateOrNull($this->first($item, ['first_expiration_on', 'expiration_date'])),
                ]);
            }

            return $query->load('client', 'items');
        });
    }

    public function storeError(string $clientNumber, array $context, string $message): DebtQuery
    {
        return DB::transaction(function () use ($clientNumber, $context, $message) {
            $client = $this->upsertClient($clientNumber, []);

            return DebtQuery::create([
                'client_id' => $client->id,
                'client_number' => $clientNumber,
                'status' => DebtQuery::ERROR,
                'job_id' => $context['job_id'] ?? null,
                'job_url' => $context['url'] ?? null,
                'raw_response' => ['error' => ['message' => $message, 'context' => $context]],
                'queried_at' => now(),
            ]);
        });
    }

    private function upsertClient(string $clientNumber, array $account): Client
    {
        $client = Client::firstOrNew(['client_number' => $clientNumber]);
        foreach (['holder_name', 'address', 'city', 'rate'] as $field) {
            if (array_key_exists($field, $account) && $account[$field] !== null) {
                $client->{$field} = $account[$field];
            }
        }
        $client->is_active = true;
        $client->save();

        return $client;
    }

    private function first(array $item, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $item)) {
                return $item[$key];
            }
        }

        return null;
    }

    private function dateOrNull(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
