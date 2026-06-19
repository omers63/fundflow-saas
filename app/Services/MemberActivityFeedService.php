<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\CashOutRequest;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\FeeDeduction;
use App\Models\Tenant\FundPosting;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\LoanRepayment;
use App\Models\Tenant\Member;
use App\Models\Tenant\Transaction;
use App\Support\MemberDateDisplay;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

final class MemberActivityFeedService
{
    public const FILTER_ALL = 'all';

    public const FILTER_CONTRIBUTIONS = 'contributions';

    public const FILTER_EMI = 'emi';

    public const FILTER_DEPOSITS = 'deposits';

    public const FILTER_LATE_FEES = 'late_fees';

    public const FILTER_LOAN_EVENTS = 'loan_events';

    public const FILTER_CASH_OUTS = 'cash_outs';

    /**
     * @return list<array{key: string, label: string}>
     */
    public function filterOptions(): array
    {
        return [
            ['key' => self::FILTER_ALL, 'label' => __('All')],
            ['key' => self::FILTER_CONTRIBUTIONS, 'label' => __('Contributions')],
            ['key' => self::FILTER_EMI, 'label' => __('EMI')],
            ['key' => self::FILTER_DEPOSITS, 'label' => __('Deposits')],
            ['key' => self::FILTER_LATE_FEES, 'label' => __('Late fees')],
            ['key' => self::FILTER_LOAN_EVENTS, 'label' => __('Loan events')],
            ['key' => self::FILTER_CASH_OUTS, 'label' => __('Cash outs')],
        ];
    }

    /**
     * @return LengthAwarePaginator<int, Transaction>
     */
    public function paginate(Member $member, ?string $filter = null, int $perPage = 25): LengthAwarePaginator
    {
        return $this->applyFilter($this->baseQuery($member), $filter)
            ->orderByDesc('transacted_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * @return Builder<Transaction>
     */
    public function exportQuery(Member $member, ?CarbonInterface $from, ?CarbonInterface $to, ?string $filter = null): Builder
    {
        $query = $this->applyFilter($this->baseQuery($member), $filter);

        if ($from !== null) {
            $query->where('transacted_at', '>=', $from->copy()->startOfDay());
        }

        if ($to !== null) {
            $query->where('transacted_at', '<=', $to->copy()->endOfDay());
        }

        return $query
            ->with('account')
            ->orderByDesc('transacted_at')
            ->orderByDesc('id');
    }

    /**
     * @return list<string>
     */
    public function exportCsvHeaders(): array
    {
        return [
            __('Date'),
            __('Time'),
            __('Account'),
            __('Category'),
            __('Description'),
            __('Credit'),
            __('Debit'),
            __('Balance after'),
            __('Reference'),
            __('Currency'),
        ];
    }

    /**
     * @return list<string|int|float>
     */
    public function mapExportRow(Transaction $transaction, string $currency): array
    {
        $amount = number_format((float) $transaction->amount, 2, '.', '');

        return [
            MemberDateDisplay::format($transaction->transacted_at, 'Y-m-d') ?? '',
            MemberDateDisplay::format($transaction->transacted_at, 'H:i:s') ?? '',
            $transaction->account?->memberFacingLabel() ?? '—',
            $transaction->memberActivityCategoryLabel(),
            $transaction->memberFacingDescription(),
            $transaction->type === 'credit' ? $amount : '',
            $transaction->type === 'debit' ? $amount : '',
            number_format((float) $transaction->balance_after, 2, '.', ''),
            $transaction->memberExportReference(),
            $currency,
        ];
    }

    /**
     * @return array{description: string, date: string, credit: ?float, debit: ?float, type: string, type_label: string, account: string}
     */
    public function mapRow(Transaction $transaction): array
    {
        $amount = (float) $transaction->amount;

        return [
            'description' => $transaction->memberFacingDescription(),
            'date' => MemberDateDisplay::format($transaction->transacted_at, 'j M Y') ?? '—',
            'account' => $transaction->account?->memberFacingLabel() ?? '—',
            'credit' => $transaction->type === 'credit' ? $amount : null,
            'debit' => $transaction->type === 'debit' ? $amount : null,
            'type' => $transaction->type === 'credit' ? 'CR' : 'DR',
            'type_label' => $transaction->type === 'credit' ? __('Credit') : __('Debit'),
        ];
    }

    /**
     * @return Builder<Transaction>
     */
    public function baseQuery(Member $member): Builder
    {
        $accountIds = $member->accounts()->pluck('id');

        if ($accountIds->isEmpty()) {
            return Transaction::query()->whereRaw('0 = 1');
        }

        return Transaction::query()->whereIn('account_id', $accountIds);
    }

    /**
     * @param  Builder<Transaction>  $query
     * @return Builder<Transaction>
     */
    public function applyFilter(Builder $query, ?string $filter): Builder
    {
        $filter = $filter ?: self::FILTER_ALL;

        if ($filter === self::FILTER_ALL) {
            return $query;
        }

        return match ($filter) {
            self::FILTER_CONTRIBUTIONS => $this->excludeLateFeeDescriptions(
                $query->where('reference_type', Contribution::class),
            ),
            self::FILTER_EMI => $this->excludeLateFeeDescriptions(
                $query->whereIn('reference_type', [LoanInstallment::class, LoanRepayment::class]),
            ),
            self::FILTER_DEPOSITS => $query->where('reference_type', FundPosting::class),
            self::FILTER_LATE_FEES => $query->where(function (Builder $inner): void {
                $inner->where('reference_type', FeeDeduction::class)
                    ->orWhere('description', 'like', $this->contributionLateFeePrefix().'%')
                    ->orWhere('description', 'like', $this->emiLateFeePrefix().'%');
            }),
            self::FILTER_LOAN_EVENTS => $query->where('reference_type', Loan::class),
            self::FILTER_CASH_OUTS => $query->where('reference_type', CashOutRequest::class),
            default => $query,
        };
    }

    /**
     * @param  Builder<Transaction>  $query
     * @return Builder<Transaction>
     */
    private function excludeLateFeeDescriptions(Builder $query): Builder
    {
        return $query
            ->where('description', 'not like', $this->contributionLateFeePrefix().'%')
            ->where('description', 'not like', $this->emiLateFeePrefix().'%');
    }

    private function contributionLateFeePrefix(): string
    {
        return __('Contribution late fee —');
    }

    private function emiLateFeePrefix(): string
    {
        return __('EMI late fee —');
    }
}
