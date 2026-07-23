<?php

declare(strict_types=1);

namespace App\Services\Tenant;

use App\Models\Tenant\Member;
use App\Services\ContributionCycleService;
use App\Services\Loans\LoanDelinquencyService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Resolves portal-eligible member cohorts for announcements and bulk messaging.
 */
final class MemberAudienceResolver
{
    public const ALL_WITH_LOGIN = 'all_with_login';

    public const ALL_ACTIVE = 'all_active';

    public const INACTIVE = 'inactive';

    public const WITHDRAWN = 'withdrawn';

    public const WITH_ACTIVE_LOANS = 'with_active_loans';

    public const OVERDUE_CONTRIBUTIONS = 'overdue';

    public const PENDING_CONTRIBUTIONS = 'pending_contributions';

    public const DELINQUENT = 'delinquent';

    public const OVERDUE_LOAN_INSTALLMENTS = 'overdue_loan_installments';

    public const MIGRATION_PENDING = 'migration_pending';

    public const GUARANTORS = 'guarantors';

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::ALL_ACTIVE => __('Active members'),
            self::ALL_WITH_LOGIN => __('All members with portal login'),
            self::INACTIVE => __('Inactive members'),
            self::WITHDRAWN => __('Withdrawn members'),
            self::WITH_ACTIVE_LOANS => __('Members with active loans'),
            self::OVERDUE_CONTRIBUTIONS => __('Members with overdue contributions'),
            self::PENDING_CONTRIBUTIONS => __('Members with uncollected contributions (open cycle)'),
            self::DELINQUENT => __('Delinquent / arrears members'),
            self::OVERDUE_LOAN_INSTALLMENTS => __('Members with overdue loan installments'),
            self::MIGRATION_PENDING => __('Migration-pending members'),
            self::GUARANTORS => __('Guarantors on active loans'),
        ];
    }

    /**
     * Subset historically used by announcements (keys preserved).
     *
     * @return array<string, string>
     */
    public static function announcementOptions(): array
    {
        $all = self::options();

        return [
            self::ALL_ACTIVE => $all[self::ALL_ACTIVE],
            self::OVERDUE_CONTRIBUTIONS => $all[self::OVERDUE_CONTRIBUTIONS],
            self::DELINQUENT => $all[self::DELINQUENT],
            self::WITH_ACTIVE_LOANS => $all[self::WITH_ACTIVE_LOANS],
            self::PENDING_CONTRIBUTIONS => $all[self::PENDING_CONTRIBUTIONS],
            self::OVERDUE_LOAN_INSTALLMENTS => $all[self::OVERDUE_LOAN_INSTALLMENTS],
            self::INACTIVE => $all[self::INACTIVE],
            self::GUARANTORS => $all[self::GUARANTORS],
        ];
    }

    public function previewCount(string $audience): int
    {
        return $this->resolve($audience)->count();
    }

    /**
     * Members with a portal login for the selected cohort.
     *
     * @return Collection<int, Member>
     */
    public function resolve(string $audience): Collection
    {
        $query = Member::query()
            ->whereNotNull('user_id')
            ->with('user');

        return $this->applyAudience($query, $audience)->get();
    }

    public function applyAudience(Builder $query, string $audience): Builder
    {
        return match ($audience) {
            self::ALL_WITH_LOGIN => $query,
            self::INACTIVE => $query->where('status', 'inactive'),
            self::WITHDRAWN => $query->where('status', 'withdrawn'),
            self::WITH_ACTIVE_LOANS => $query->whereHas(
                'loans',
                fn (Builder $q): Builder => $q->whereIn('status', ['active', 'transferred', 'repaying', 'disbursed', 'partially_disbursed']),
            ),
            self::OVERDUE_CONTRIBUTIONS => $query->whereHas(
                'contributions',
                fn (Builder $q): Builder => $q->where('status', 'overdue'),
            ),
            self::PENDING_CONTRIBUTIONS => $this->scopePendingOpenCycleContributions($query),
            self::DELINQUENT => $query->whereIn('id', app(LoanDelinquencyService::class)->delinquentMemberIds()),
            self::OVERDUE_LOAN_INSTALLMENTS => $query->whereHas(
                'loans.installments',
                fn (Builder $q): Builder => $q->where('status', 'overdue'),
            ),
            self::MIGRATION_PENDING => $query->whereIn(
                'id',
                app(MemberListTabService::class)->migrationPendingMemberIds(),
            ),
            self::GUARANTORS => $query->whereHas(
                'guaranteedLoans',
                fn (Builder $q): Builder => $q->whereIn('status', ['active', 'transferred', 'repaying', 'disbursed', 'partially_disbursed']),
            ),
            default => $query->where('status', 'active'),
        };
    }

    private function scopePendingOpenCycleContributions(Builder $query): Builder
    {
        [$month, $year] = app(ContributionCycleService::class)->currentOpenPeriod();

        return $query->whereHas(
            'contributions',
            fn (Builder $q): Builder => $q
                ->where('status', 'pending')
                ->forPeriod($month, $year),
        );
    }
}
