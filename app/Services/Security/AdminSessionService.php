<?php

declare(strict_types=1);

namespace App\Services\Security;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class AdminSessionService
{
    /**
     * @return Collection<int, object{
     *     id: string,
     *     ip_address: ?string,
     *     user_agent: ?string,
     *     last_activity: int,
     *     is_current: bool
     * }>
     */
    public function listForUser(int $userId): Collection
    {
        if (! Schema::hasTable('sessions')) {
            return collect();
        }

        $currentId = session()->getId();

        return DB::table('sessions')
            ->where('user_id', $userId)
            ->orderByDesc('last_activity')
            ->get()
            ->map(function (object $row) use ($currentId): object {
                $row->is_current = $row->id === $currentId;

                return $row;
            });
    }

    public function revoke(string $sessionId, int $userId): bool
    {
        if (! Schema::hasTable('sessions')) {
            return false;
        }

        if ($sessionId === session()->getId()) {
            return false;
        }

        return DB::table('sessions')
            ->where('id', $sessionId)
            ->where('user_id', $userId)
            ->delete() > 0;
    }

    public function revokeOthers(int $userId): int
    {
        if (! Schema::hasTable('sessions')) {
            return 0;
        }

        return DB::table('sessions')
            ->where('user_id', $userId)
            ->where('id', '!=', session()->getId())
            ->delete();
    }
}
