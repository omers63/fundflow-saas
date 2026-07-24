<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Member;
use App\Models\Tenant\PortalAccessLog;
use App\Models\Tenant\User;
use App\Support\SystemLoggingSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

final class PortalAccessLogService
{
    public function record(
        User $user,
        string $panel,
        ?Member $member = null,
        ?Request $request = null,
    ): ?PortalAccessLog {
        if (! SystemLoggingSettings::portalAccessLogEnabled()) {
            return null;
        }

        if (! Schema::hasTable('portal_access_logs')) {
            return null;
        }

        $request ??= request();
        $member ??= $user->member;

        return PortalAccessLog::query()->create([
            'user_id' => $user->id,
            'member_id' => $member?->id,
            'member_name' => $member?->name ?? ($panel === PortalAccessLog::PANEL_ADMIN ? $user->name : null),
            'panel' => $panel,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'accessed_at' => now(),
        ]);
    }
}
