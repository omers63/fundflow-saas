<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\User;
use App\Support\BusinessDay;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notification;

final class OperationalReviewWorkflowService
{
    /**
     * @param  array<string, mixed>  $extraUpdates
     */
    public function markReviewed(
        Model $record,
        string $status,
        ?int $reviewedBy = null,
        ?string $remarks = null,
        ?\DateTimeInterface $reviewedAt = null,
        array $extraUpdates = [],
    ): void {
        $record->update(array_merge([
            'status' => $status,
            'reviewed_by' => $reviewedBy,
            'reviewed_at' => $reviewedAt ?? BusinessDay::now(),
            'admin_remarks' => $remarks,
        ], $extraUpdates));
    }

    public function notifyAdmins(Notification $notification): void
    {
        User::query()
            ->where('is_admin', true)
            ->each(function (User $admin) use ($notification): void {
                $admin->notify(clone $notification);
            });
    }
}
