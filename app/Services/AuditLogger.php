<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Http\Request;

class AuditLogger
{
    public function record(string $event, array $metadata = [], ?object $subject = null, ?Request $request = null): AuditLog
    {
        $request ??= request();

        return AuditLog::create([
            'actor_user_id' => $request?->user()?->id,
            'event' => $event,
            'subject_type' => $subject ? $subject::class : null,
            'subject_id' => $subject?->id,
            'metadata' => $metadata,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
        ]);
    }
}
