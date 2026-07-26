<?php

declare(strict_types=1);

namespace App\Services\Loans;

use App\Filament\Tenant\Resources\Contributions\ContributionResource;
use App\Filament\Tenant\Resources\Members\MemberResource;
use App\Filament\Tenant\Support\DelinquencyTabRegistry;
use App\Models\Tenant\User;
use App\Notifications\Tenant\DelinquencyDigestNotification;
use App\Support\AutomationScheduleSettings;

class DelinquencyDigestService
{
    public function __construct(protected LoanDelinquencyService $delinquency) {}

    /**
     * Notify tenant admins when there is delinquency activity worth reviewing.
     */
    public function notifyAdminsIfNeeded(): int
    {
        if (! AutomationScheduleSettings::notifyDelinquencyDigest()) {
            return 0;
        }

        $counts = $this->delinquency->digestCounts();

        $total = $counts['overdue_installments']
            + $counts['contribution_arrears_periods']
            + $counts['members_in_arrears']
            + $counts['delinquent_members']
            + $counts['guarantor_at_risk']
            + $counts['guarantor_transferred'];

        if ($total === 0) {
            return 0;
        }

        $url = $this->primaryReviewUrl($counts);
        $notified = 0;

        User::query()
            ->where('is_admin', true)
            ->each(function (User $user) use ($counts, $url, &$notified): void {
                $user->notify(new DelinquencyDigestNotification($counts, $url));
                $notified++;
            });

        return $notified;
    }

    /**
     * @param  array<string, int>  $counts
     */
    protected function primaryReviewUrl(array $counts): string
    {
        if (($counts['overdue_installments'] ?? 0) > 0) {
            return DelinquencyTabRegistry::url('overdue');
        }

        if (($counts['guarantor_at_risk'] ?? 0) > 0 || ($counts['guarantor_transferred'] ?? 0) > 0) {
            return DelinquencyTabRegistry::url('guarantor');
        }

        if (($counts['delinquent_members'] ?? 0) > 0) {
            return DelinquencyTabRegistry::url('policy');
        }

        if (($counts['members_in_arrears'] ?? 0) > 0) {
            return MemberResource::listTabUrl('delinquent');
        }

        if (($counts['contribution_arrears_periods'] ?? 0) > 0) {
            return ContributionResource::listTabUrl('arrears');
        }

        return DelinquencyTabRegistry::url('overview');
    }
}
