<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\FundAuditLog;
use App\Models\Tenant\NotificationLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class SystemLogMaintenanceService
{
    public function fundAuditLogRowCount(): int
    {
        if (! Schema::hasTable('fund_audit_logs')) {
            return 0;
        }

        return (int) FundAuditLog::query()->count();
    }

    public function notificationLogRowCount(): int
    {
        if (! Schema::hasTable('notification_logs')) {
            return 0;
        }

        return (int) NotificationLog::query()->withTrashed()->count();
    }

    public function truncateFundAuditLogs(): int
    {
        if (! Schema::hasTable('fund_audit_logs')) {
            return 0;
        }

        $count = $this->fundAuditLogRowCount();

        if ($count === 0) {
            return 0;
        }

        Schema::disableForeignKeyConstraints();

        try {
            DB::table('fund_audit_logs')->truncate();
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        return $count;
    }

    public function truncateNotificationLogs(): int
    {
        if (! Schema::hasTable('notification_logs')) {
            return 0;
        }

        $count = $this->notificationLogRowCount();

        if ($count === 0) {
            return 0;
        }

        Schema::disableForeignKeyConstraints();

        try {
            DB::table('notification_logs')->truncate();
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        return $count;
    }
}
