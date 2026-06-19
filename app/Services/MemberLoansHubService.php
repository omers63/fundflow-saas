<?php

declare(strict_types=1);

namespace App\Services;

use App\Filament\Member\Resources\MyLoans\MyLoanResource;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Support\Insights\InsightFormatter;
use App\Support\MemberDateDisplay;
use Illuminate\Database\Eloquent\Collection;

final class MemberLoansHubService
{
    /**
     * @return list<string>
     */
    public static function activePipelineStatuses(): array
    {
        return ['pending', 'approved', 'partially_disbursed', 'active'];
    }

    /**
     * @return list<string>
     */
    public static function historyStatuses(): array
    {
        return ['completed', 'early_settled', 'rejected', 'cancelled', 'transferred'];
    }

    /**
     * @return Collection<int, Loan>
     */
    public function activeLoans(Member $member): Collection
    {
        return Loan::query()
            ->where('member_id', $member->id)
            ->whereIn('status', self::activePipelineStatuses())
            ->with(['guarantor', 'installments', 'loanTier'])
            ->orderByDesc('applied_at')
            ->get();
    }

    public function activePipelineCount(Member $member): int
    {
        return Loan::query()
            ->where('member_id', $member->id)
            ->whereIn('status', self::activePipelineStatuses())
            ->count();
    }

    public function historyCount(Member $member): int
    {
        return Loan::query()
            ->where('member_id', $member->id)
            ->whereIn('status', self::historyStatuses())
            ->count();
    }

    public function settleLoan(Member $member): ?Loan
    {
        return Loan::query()
            ->where('member_id', $member->id)
            ->where('status', 'active')
            ->with(['guarantor', 'installments', 'loanTier'])
            ->orderByDesc('applied_at')
            ->first();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function activeLoanCards(Member $member): array
    {
        return $this->activeLoans($member)
            ->map(fn (Loan $loan): array => $this->loanCard($loan))
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, Loan>
     */
    public function historyLoans(Member $member): Collection
    {
        return Loan::query()
            ->where('member_id', $member->id)
            ->whereIn('status', self::historyStatuses())
            ->with(['guarantor', 'installments', 'loanTier'])
            ->orderByDesc('applied_at')
            ->get();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function historyLoanCards(Member $member): array
    {
        return $this->historyLoans($member)
            ->map(fn (Loan $loan): array => $this->historyLoanCard($loan))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function historyLoanCard(Loan $loan): array
    {
        $card = $this->loanCard($loan);

        $card['status_variant'] = match ($loan->status) {
            'rejected', 'cancelled' => 'red',
            'completed', 'early_settled' => 'green',
            default => 'gray',
        };

        $card['meta'] = collect([
            Loan::statusOptions()[$loan->status] ?? $loan->status,
            $loan->settled_at
            ? __('Settled :date', ['date' => MemberDateDisplay::format($loan->settled_at, 'M Y')])
            : null,
            $loan->disbursed_at
            ? __('Disbursed :date', ['date' => MemberDateDisplay::format($loan->disbursed_at, 'M Y')])
            : null,
            $loan->applied_at
            ? __('Applied :date', ['date' => MemberDateDisplay::format($loan->applied_at, 'M Y')])
            : null,
        ])->filter()->unique()->implode(' · ');

        $card['schedule_pdf_url'] = in_array($loan->status, ['completed', 'early_settled'], true)
            ? route('tenant.member.loan.schedule.pdf', ['loan' => $loan])
            : null;
        $card['show_settle_button'] = false;
        $card['show_schedule'] = in_array($loan->status, ['completed', 'early_settled'], true);

        return $card;
    }

    /**
     * @return array<string, mixed>
     */
    public function loanCard(Loan $loan): array
    {
        $loan->loadMissing(['guarantor', 'installments', 'loanTier']);

        $outstanding = $loan->getOutstandingBalance();
        $installmentsTotal = $loan->installments->count();
        $installmentsPaid = $loan->installments->where('status', 'paid')->count();
        $repayPercent = $installmentsTotal > 0 ? (int) round(($installmentsPaid / $installmentsTotal) * 100) : 0;
        $totalRepaid = max(0, (float) $loan->amount - $outstanding);

        $nextInstallment = $loan->installments
            ->whereIn('status', ['pending', 'overdue'])
            ->sortBy('due_date')
            ->first();

        return [
            'id' => $loan->id,
            'label' => __('Loan #:id', ['id' => $loan->id]),
            'status' => $loan->status,
            'status_label' => Loan::statusOptions()[$loan->status] ?? $loan->status,
            'status_variant' => in_array($loan->status, ['active', 'approved', 'partially_disbursed'], true) ? 'green' : 'amber',
            'meta' => collect([
                $loan->approved_at ? __('Approved :date', ['date' => MemberDateDisplay::format($loan->approved_at, 'M Y')]) : null,
                $loan->loanTier?->label,
                $loan->monthly_repayment ? __('EMI :amount', ['amount' => InsightFormatter::money((float) $loan->monthly_repayment)]) : null,
            ])->filter()->implode(' · '),
            'outstanding' => $outstanding,
            'repay_percent' => $repayPercent,
            'installments_label' => __(':paid of :total EMIs', [
                'paid' => $installmentsPaid,
                'total' => $installmentsTotal,
            ]),
            'repaid_label' => __(':amount repaid (:percent%)', [
                'amount' => InsightFormatter::money($totalRepaid),
                'percent' => $repayPercent,
            ]),
            'guarantor_name' => $loan->guarantor?->name,
            'next_emi' => $nextInstallment instanceof LoanInstallment ? [
                'amount' => (float) $nextInstallment->amount,
                'due_date' => MemberDateDisplay::format($nextInstallment->due_date, 'j M Y'),
            ] : null,
            'view_url' => MyLoanResource::getUrl('view', ['record' => $loan]),
            'schedule_pdf_url' => $loan->status === 'active'
                ? route('tenant.member.loan.schedule.pdf', ['loan' => $loan])
                : null,
            'settle_url' => MyLoanResource::getUrl('index', ['hub' => 'settle']),
            'show_settle_button' => $loan->status === 'active',
            'show_schedule' => in_array($loan->status, ['active', 'approved', 'partially_disbursed', 'pending'], true),
        ];
    }
}
