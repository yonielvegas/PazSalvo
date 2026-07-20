<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Http\Request;

class AuditLogger
{
    public function record(string $event, array $metadata = [], ?object $subject = null, ?Request $request = null, ?string $result = null): AuditLog
    {
        $request ??= request();
        $metadata = $this->sanitize($metadata);

        return AuditLog::create([
            'actor_user_id' => $request?->user()?->id,
            'event' => $event,
            'subject_type' => $subject ? $subject::class : null,
            'subject_id' => $subject?->id,
            'metadata' => $metadata,
            'request_id' => $request?->headers->get('X-Request-Id') ?: $request?->attributes->get('request_id'),
            'result' => $result,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
        ]);
    }

    private function sanitize(array $metadata): array
    {
        foreach ($metadata as $key => $value) {
            $lower = strtolower((string) $key);
            if (str_contains($lower, 'password') || str_contains($lower, 'secret') || str_contains($lower, 'token') || str_contains($lower, 'cookie') || str_contains($lower, 'session')) {
                $metadata[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $metadata[$key] = $this->sanitize($value);
            }
        }

        return $metadata;
    }
}
