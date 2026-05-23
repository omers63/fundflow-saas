<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\FundAuditLog;
use App\Models\Tenant\Member;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class FundAuditLogService
{
    public function log(
        string $eventType,
        ?string $domain = null,
        ?Model $subject = null,
        ?Member $member = null,
        ?array $payload = null,
    ): FundAuditLog {
        $occurredAt = now();
        $operatorId = Auth::guard('tenant')->id();

        $payloadJson = json_encode($payload ?? [], JSON_THROW_ON_ERROR);
        $checksum = hash('sha256', implode('|', [
            $eventType,
            $domain ?? '',
            $subject ? $subject::class.':'.$subject->getKey() : '',
            (string) ($member?->id ?? ''),
            (string) $operatorId,
            $occurredAt->toIso8601String(),
            $payloadJson,
        ]));

        return FundAuditLog::create([
            'event_type' => $eventType,
            'domain' => $domain,
            'subject_type' => $subject ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'member_id' => $member?->id,
            'operator_id' => $operatorId,
            'payload' => $payload,
            'checksum' => $checksum,
            'occurred_at' => $occurredAt,
        ]);
    }
}
