<?php

declare(strict_types=1);

namespace App\Services\Loans;

use App\Filament\Tenant\Resources\Loans\LoanResource;
use App\Models\Tenant\User;
use App\Notifications\Tenant\DelinquencyDigestNotification;

class DelinquencyDigestService
{
    public function __construct(protected LoanDelinquencyService $delinquency) {}

    /**
     * Notify tenant admins when there is delinquency activity worth reviewing.
     */
    public function notifyAdminsIfNeeded(): int
    {
        $counts = $this->delinquency->digestCounts();

        $total = $counts['overdue_installments']
            + $counts['contribution_arrears_periods']
            + $counts['guarantor_at_risk'];

        if ($total === 0) {
            return 0;
        }

        $url = LoanResource::getUrl('delinquency');
        $notified = 0;

        User::query()
            ->where('is_admin', true)
            ->each(function (User $user) use ($counts, $url, &$notified): void {
                $user->notify(new DelinquencyDigestNotification($counts, $url));
                $notified++;
            });

        return $notified;
    }
}
