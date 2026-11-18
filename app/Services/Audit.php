<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

class Audit
{
    public static function log(string $action, ?Model $record = null, array $details = []): void
    {
        $req = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();
        AuditLog::create([
            'user_id' => auth()->id(),
            'action' => $action,
            'record_type' => $record?->getMorphClass(),
            'record_id' => $record?->getKey(),
            'details' => $details ? json_decode(json_encode($details, JSON_PARTIAL_OUTPUT_ON_ERROR), true) : null,
            'ip' => $req?->ip(),
            'user_agent' => $req ? mb_substr((string) $req->userAgent(), 0, 255) : 'console',
            'created_at' => now(),
        ]);
    }
}
