<?php

declare(strict_types=1);

namespace App\Filament\Member\Resources\MyDependents\Support;

use App\Filament\Support\LateSettledArrearsTableStyling;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Services\ContributionCycleService;
use App\Services\Loans\LoanEmiCollectionCatalogService;
use Illuminate\Support\Collection;

final class DependentOpenCycleStatus
{
    /**
     * @return array{label: string, color: string, description: ?string}
     */
    public static function resolve(Member $member, int $month, int $year): array
    {
        $emi = self::emiStatus($member, $month, $year);
        $contribution = self::contributionStatus($member, $month, $year);

        if ($emi !== null) {
            return [
                'label' => __('EMI: :status', ['status' => $emi['label']]),
                'color' => $emi['color'],
                'description' => __('Contribution: :status', ['status' => $contribution['label']]),
            ];
        }

        return [
            'label' => $contribution['label'],
            'color' => $contribution['color'],
            'description' => null,
        ];
    }

    /**
     * @return array{label: string, color: string}|null
     */
    private static function emiStatus(Member $member, int $month, int $year): ?array
    {
        $catalog = app(LoanEmiCollectionCatalogService::class);
        $open = $catalog->collectableInstallmentsForMemberInPeriod($member, $month, $year);

        if ($open->isNotEmpty()) {
            return self::summarizeOpenInstallments($open);
        }

        $inPeriod = self::installmentsDueInPeriod($member, $month, $year);

        if ($inPeriod->isEmpty()) {
            return null;
        }

        if ($inPeriod->every(fn (LoanInstallment $installment): bool => $installment->isPaid())) {
            return self::summarizePaidInstallments($inPeriod);
        }

        $unpaid = $inPeriod->filter(fn (LoanInstallment $installment): bool => ! $installment->isPaid());

        return self::summarizeOpenInstallments($unpaid);
    }

    /**
     * @return Collection<int, LoanInstallment>
     */
    private static function installmentsDueInPeriod(Member $member, int $month, int $year): Collection
    {
        $cycles = app(ContributionCycleService::class);
        [$start, $end] = $cycles->cycleDueDateBounds($month, $year);

        return LoanInstallment::query()
            ->whereHas(
                'loan',
                fn ($query) => $query
                    ->whereIn('status', ['active', 'transferred'])
                    ->where('member_id', $member->id),
            )
            ->whereBetween('due_date', [$start, $end])
            ->orderBy('due_date')
            ->get();
    }

    /**
     * @param  Collection<int, LoanInstallment>  $installments
     * @return array{label: string, color: string}
     */
    private static function summarizeOpenInstallments(Collection $installments): array
    {
        if ($installments->contains(fn (LoanInstallment $installment): bool => $installment->status === 'overdue')) {
            return ['label' => __('Overdue'), 'color' => 'danger'];
        }

        return ['label' => __('Pending'), 'color' => 'warning'];
    }

    /**
     * @param  Collection<int, LoanInstallment>  $installments
     * @return array{label: string, color: string}
     */
    private static function summarizePaidInstallments(Collection $installments): array
    {
        $late = $installments->contains(
            fn (LoanInstallment $installment): bool => LateSettledArrearsTableStyling::installmentWasSettledLate($installment),
        );

        return [
            'label' => $late ? __('Paid (late)') : __('Paid'),
            'color' => $late ? 'danger' : 'success',
        ];
    }

    /**
     * @return array{label: string, color: string}
     */
    private static function contributionStatus(Member $member, int $month, int $year): array
    {
        if ($member->isExemptFromContributions($month, $year)) {
            return ['label' => __('Exempt'), 'color' => 'gray'];
        }

        if ((float) $member->monthly_contribution_amount <= 0) {
            return ['label' => __('Not due'), 'color' => 'gray'];
        }

        $contribution = Contribution::query()
            ->where('member_id', $member->id)
            ->forPeriod($month, $year)
            ->first();

        if ($contribution === null) {
            return ['label' => __('Not started'), 'color' => 'gray'];
        }

        return [
            'label' => LateSettledArrearsTableStyling::contributionStatusLabel($contribution),
            'color' => LateSettledArrearsTableStyling::contributionStatusColor($contribution),
        ];
    }
}
