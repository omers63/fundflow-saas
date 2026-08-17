<?php

declare(strict_types=1);

namespace App\Support;

use App\Filament\Support\MoneyDisplay;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\CashOutRequest;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\ExpenseDisbursement;
use App\Models\Tenant\FeeDeduction;
use App\Models\Tenant\FeeDisbursement;
use App\Models\Tenant\FundOutRequest;
use App\Models\Tenant\FundPosting;
use App\Models\Tenant\InvestDisbursement;
use App\Models\Tenant\InvestReturn;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\LoanRepayment;
use App\Models\Tenant\Member;
use App\Models\Tenant\MemberCashTransferRequest;
use App\Models\Tenant\MembershipApplication;
use App\Models\Tenant\MonthlyStatement;
use App\Models\Tenant\Transaction;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Line-item inventory of window activity for the rollback preview modal.
 */
final class BusinessDayWindowRollbackEventInventory
{
    /**
     * @param  Collection<int, Contribution>  $contributions
     * @param  Collection<int, LoanInstallment>  $installments
     * @param  Collection<int, FundPosting>  $deposits
     * @param  Collection<int, CashOutRequest>  $cashOuts
     * @param  Collection<int, Model>  $disbursements
     * @param  Collection<int, Transaction>  $journals
     * @param  Collection<int, MembershipApplication>  $applications
     * @param  Collection<int, Model>  $otherSources
     * @param  Collection<int, Loan>  $earlySettlements
     * @param  Collection<int, Member>  $withdrawals
     * @param  Collection<int, Member>  $windowFreezes
     * @param  Collection<int, Member>  $freezeTicks
     * @param  Collection<int, Loan>  $transfers
     * @param  Collection<int, BankTransaction>  $matches
     * @param  Collection<int, MonthlyStatement>  $statements
     * @param  Collection<int, Contribution>  $futureCycles
     * @param  Collection<int, Contribution>  $overdueContributions
     * @param  Collection<int, LoanInstallment>  $overdueInstallments
     * @param  array<int, int>  $freezeTickCounts
     * @return list<array{key: string, heading: string, events: list<array{id: string, title: string, amount: ?string, date: ?string, status: ?string, detail: ?string, meta: string}>}>
     */
    public function sections(
        Collection $contributions,
        Collection $installments,
        Collection $deposits,
        Collection $cashOuts,
        Collection $disbursements,
        Collection $journals,
        Collection $applications,
        Collection $otherSources,
        Collection $earlySettlements,
        Collection $withdrawals,
        Collection $windowFreezes,
        Collection $freezeTicks,
        Collection $transfers,
        Collection $matches,
        Collection $statements,
        Collection $futureCycles,
        Collection $overdueContributions,
        Collection $overdueInstallments,
        array $freezeTickCounts,
    ): array {
        $contributions->loadMissing('member');
        $installments->loadMissing('loan.member');
        $deposits->loadMissing('member');
        $cashOuts->loadMissing('member');
        $journals->loadMissing(['account', 'member']);
        $earlySettlements->loadMissing('member');
        $transfers->loadMissing('member');
        $matches->loadMissing('member');
        $statements->loadMissing('member');
        $futureCycles->loadMissing('member');
        $overdueContributions->loadMissing('member');
        $overdueInstallments->loadMissing('loan.member');
        $otherSources->each(function (Model $source): void {
            $source->loadMissing(array_values(array_filter([
                method_exists($source, 'member') ? 'member' : null,
                method_exists($source, 'fromMember') ? 'fromMember' : null,
                method_exists($source, 'toMember') ? 'toMember' : null,
            ])));
        });

        $overdueContributionEvents = $overdueContributions
            ->reject(fn (Contribution $row): bool => $contributions->contains(
                fn (Contribution $posted): bool => (int) $posted->id === (int) $row->id,
            ))
            ->map(fn (Contribution $row): array => $this->event(
                self::overdueKey($row),
                $this->memberName($row->member),
                $this->money((float) ($row->amount_due ?? $row->amount)),
                $this->periodLabel($row->period),
                $row->collection_status,
                __('Contribution'),
            ))
            ->values();

        $overdueInstallmentEvents = $overdueInstallments
            ->reject(fn (LoanInstallment $row): bool => $installments->contains(
                fn (LoanInstallment $paid): bool => (int) $paid->id === (int) $row->id,
            ))
            ->map(fn (LoanInstallment $row): array => $this->event(
                self::overdueKey($row),
                $this->memberName($row->loan?->member),
                $this->money((float) $row->amount),
                $this->date($row->due_date),
                $row->status,
                $this->joinMeta([
                    __('EMI #:number', ['number' => $row->installment_number]),
                    __('Loan #:id', ['id' => $row->loan_id]),
                ]),
            ))
            ->values();

        return array_values(array_filter([
            $this->section('contributions', __('Contributions'), $contributions->map(fn (Contribution $row): array => $this->event(
                self::modelKey('contributions', $row),
                $this->memberName($row->member),
                $this->money((float) ($row->amount_collected > 0 ? $row->amount_collected : $row->amount)),
                $this->dateTime($row->posted_at ?? $row->paid_at),
                $row->status,
                $this->periodLabel($row->period),
            ))),
            $this->section('installments', __('EMIs'), $installments->map(fn (LoanInstallment $row): array => $this->event(
                self::modelKey('installments', $row),
                $this->memberName($row->loan?->member),
                $this->money((float) ($row->amount_collected > 0 ? $row->amount_collected : $row->amount)),
                $this->dateTime($row->paid_at),
                $row->paid_by_guarantor ? __('Guarantor paid') : $row->status,
                $this->joinMeta([
                    __('EMI #:number', ['number' => $row->installment_number]),
                    __('Loan #:id', ['id' => $row->loan_id]),
                ]),
            ))),
            $this->section('deposits', __('Deposits'), $deposits->map(fn (FundPosting $row): array => $this->event(
                self::modelKey('deposits', $row),
                $this->memberName($row->member),
                $this->money((float) $row->amount),
                $this->dateTime($row->reviewed_at) ?? $this->date($row->posting_date),
                $row->status,
                $row->reference,
            ))),
            $this->section('cash-outs', __('Cash-outs'), $cashOuts->map(fn (CashOutRequest $row): array => $this->event(
                self::modelKey('cash-outs', $row),
                $this->memberName($row->member),
                $this->money((float) $row->amount),
                $this->dateTime($row->reviewed_at),
                $row->status,
                $row->notes,
            ))),
            $this->section('disbursements', __('Disbursements'), $disbursements->map(fn (Model $row): array => $this->event(
                self::modelKey('disbursements', $row),
                $this->disbursementLabel($row),
                $this->money((float) $row->getAttribute('amount')),
                $this->dateTime($row->getAttribute('transacted_at')),
                null,
                $row->getAttribute('description') === $this->disbursementLabel($row)
                    ? null
                    : $row->getAttribute('description'),
            ))),
            $this->section('journals', __('Manual journals'), $journals->map(fn (Transaction $row): array => $this->event(
                self::modelKey('journals', $row),
                $row->description ?: ($row->account?->name ?: __('Manual journal')),
                $this->money((float) $row->amount),
                $this->dateTime($row->transacted_at),
                $row->type,
                $this->joinMeta([
                    $this->memberName($row->member, blank: ''),
                    $row->account?->name,
                ]),
            ))),
            $this->section('applications', __('Applications'), $applications->map(fn (MembershipApplication $row): array => $this->event(
                self::modelKey('applications', $row),
                (string) ($row->name ?: $row->email ?: __('Application #:id', ['id' => $row->id])),
                $this->money((float) ($row->membership_fee_amount ?? 0)),
                $this->dateTime($row->reviewed_at),
                $row->status,
                $row->email,
            ))),
            $this->section('other-sources', __('Other sources'), $otherSources->map(fn (Model $row): array => $this->leftoverEvent($row))),
            $this->section('early-settlements', __('Early settlements'), $earlySettlements->map(fn (Loan $row): array => $this->event(
                self::modelKey('early-settlements', $row),
                $this->memberName($row->member),
                $this->money((float) $row->amount),
                $this->dateTime($row->settled_at),
                $row->status,
                __('Loan #:id', ['id' => $row->id]),
            ))),
            $this->section('withdrawals', __('Withdrawals'), $withdrawals->map(fn (Member $row): array => $this->event(
                self::modelKey('withdrawals', $row),
                $this->memberName($row),
                null,
                $this->dateTime($row->last_withdrawn_at ?? $row->status_changed_at),
                $row->payout_frozen_at ? __('Hold payout') : $row->status,
                $row->status_reason,
            ))),
            $this->section('freezes', __('Freezes'), $windowFreezes->map(fn (Member $row): array => $this->event(
                self::modelKey('freezes', $row),
                $this->memberName($row),
                null,
                $this->dateTime($row->frozen_at),
                $row->status_reason,
                $this->joinMeta([
                    __('Pushed :count cycle(s)', ['count' => (int) ($row->freeze_emi_cycles_pushed ?? 0)]),
                    __('Remaining :count', ['count' => (int) ($row->freeze_cycles_remaining ?? 0)]),
                ]),
            ))),
            $this->section('freeze-ticks', __('Freeze ticks'), $freezeTicks->map(fn (Member $row): array => $this->event(
                self::modelKey('freeze-ticks', $row),
                $this->memberName($row),
                null,
                null,
                $row->freeze_plan_ended_at ? __('Plan ended') : null,
                $this->joinMeta([
                    __('Undo :count cycle(s)', ['count' => (int) ($freezeTickCounts[(int) $row->id] ?? 0)]),
                    __('Pushed :count cycle(s)', ['count' => (int) ($row->freeze_emi_cycles_pushed ?? 0)]),
                    __('Remaining :count', ['count' => (int) ($row->freeze_cycles_remaining ?? 0)]),
                ]),
            ))),
            $this->section('guarantor-transfers', __('Guarantor transfers'), $transfers->map(fn (Loan $row): array => $this->event(
                self::modelKey('guarantor-transfers', $row),
                __('Loan #:id', ['id' => $row->id]),
                null,
                $this->dateTime($row->transferred_to_guarantor_at),
                null,
                $this->joinMeta([
                    __('Current: :name', ['name' => $this->memberName($row->member)]),
                    $row->original_borrower_member_id
                        ? __('Original borrower #:id', ['id' => $row->original_borrower_member_id])
                        : null,
                ]),
            ))),
            $this->section('bank-matches', __('Bank matches'), $matches->map(fn (BankTransaction $row): array => $this->event(
                self::modelKey('bank-matches', $row),
                $row->description ?: ($row->reference ?: $this->memberName($row->member, blank: __('Bank line #:id', ['id' => $row->id]))),
                $this->money((float) $row->amount),
                $this->dateTime($row->cleared_at) ?? $this->date($row->transaction_date),
                null,
                $this->memberName($row->member, blank: ''),
            ))),
            $this->section('statements', __('Statements'), $statements->map(fn (MonthlyStatement $row): array => $this->event(
                self::modelKey('statements', $row),
                $this->memberName($row->member),
                $this->money((float) $row->closing_balance),
                $this->dateTime($row->generated_at),
                null,
                $row->period,
            ))),
            $this->section('future-cycles', __('Future cycles'), $futureCycles->map(fn (Contribution $row): array => $this->event(
                self::modelKey('future-cycles', $row),
                $this->memberName($row->member),
                $this->money((float) ($row->amount_due ?? $row->amount)),
                $this->periodLabel($row->period),
                $row->status,
            ))),
            $this->section('overdue-resets', __('Overdue resets'), $overdueContributionEvents->concat($overdueInstallmentEvents)),
        ]));
    }

    public static function modelKey(string $type, Model $model): string
    {
        return match ($type) {
            'disbursements', 'other-sources' => $type.':'.class_basename($model).':'.$model->getKey(),
            default => $type.':'.$model->getKey(),
        };
    }

    public static function overdueKey(Contribution|LoanInstallment $row): string
    {
        $kind = $row instanceof Contribution ? 'contribution' : 'installment';

        return 'overdue-resets:'.$kind.':'.$row->getKey();
    }

    /**
     * @param  Collection<int, array{id: string, title: string, amount: ?string, date: ?string, status: ?string, detail: ?string, meta: string}>|list<array{id: string, title: string, amount: ?string, date: ?string, status: ?string, detail: ?string, meta: string}>  $events
     * @return array{key: string, heading: string, events: list<array{id: string, title: string, amount: ?string, date: ?string, status: ?string, detail: ?string, meta: string}>}|null
     */
    private function section(string $key, string $heading, Collection|array $events): ?array
    {
        $items = Collection::make($events)
            ->values()
            ->all();

        if ($items === []) {
            return null;
        }

        return [
            'key' => $key,
            'heading' => $heading,
            'events' => $items,
        ];
    }

    /**
     * @return array{id: string, title: string, amount: ?string, date: ?string, status: ?string, detail: ?string, meta: string}
     */
    private function event(
        string $id,
        string $title,
        ?string $amount = null,
        ?string $date = null,
        ?string $status = null,
        ?string $detail = null,
    ): array {
        return [
            'id' => $id,
            'title' => $title,
            'amount' => $amount,
            'date' => $date,
            'status' => $status,
            'detail' => $detail,
            'meta' => $this->joinMeta([$detail, $amount, $date, $status]),
        ];
    }

    /**
     * @return array{id: string, title: string, amount: ?string, date: ?string, status: ?string, detail: ?string, meta: string}
     */
    private function leftoverEvent(Model $source): array
    {
        $id = self::modelKey('other-sources', $source);

        if ($source instanceof FundOutRequest) {
            return $this->event(
                $id,
                $this->memberName($source->member),
                $this->money((float) $source->amount),
                $this->dateTime($source->reviewed_at),
                $source->status,
                $this->joinMeta([__('Fund-out'), $source->notes]),
            );
        }

        if ($source instanceof MemberCashTransferRequest) {
            return $this->event(
                $id,
                __('Cash transfer'),
                $this->money((float) $source->amount),
                $this->dateTime($source->reviewed_at),
                $source->status,
                $this->joinMeta([
                    $this->memberName($source->fromMember),
                    $this->memberName($source->toMember),
                ]),
            );
        }

        if ($source instanceof FeeDeduction) {
            return $this->event(
                $id,
                $this->memberName($source->member),
                $this->money((float) $source->amount),
                $this->dateTime($source->transacted_at),
                null,
                $this->joinMeta([__('Fee deduction'), $source->description]),
            );
        }

        if ($source instanceof LoanRepayment) {
            return $this->event(
                $id,
                __('Loan #:id', ['id' => $source->loan_id]),
                $this->money((float) $source->amount),
                $this->dateTime($source->paid_at),
                null,
                $this->joinMeta([__('Repayment'), $source->notes]),
            );
        }

        if ($source instanceof Loan) {
            return $this->event(
                $id,
                $this->memberName($source->member ?? null),
                $this->money((float) $source->amount_disbursed),
                $this->dateTime($source->disbursed_at),
                $source->status,
                __('Loan #:id', ['id' => $source->id]),
            );
        }

        if ($source instanceof Member) {
            return $this->event(
                $id,
                $this->memberName($source),
                null,
                null,
                null,
                $this->joinMeta([__('Member ledger'), $source->member_number]),
            );
        }

        $amount = $source->getAttribute('amount');

        return $this->event(
            $id,
            class_basename($source).' #'.$source->getKey(),
            is_numeric($amount) ? $this->money((float) $amount) : null,
            $this->dateTime($source->getAttribute('reviewed_at') ?? $source->getAttribute('transacted_at')),
            $source->getAttribute('status'),
        );
    }

    private function disbursementLabel(Model $row): string
    {
        $description = trim((string) ($row->getAttribute('description') ?? ''));

        if ($description !== '') {
            return $description;
        }

        return match ($row::class) {
            ExpenseDisbursement::class => __('Expense disbursement'),
            FeeDisbursement::class => __('Fee disbursement'),
            InvestDisbursement::class => __('Investment disbursement'),
            InvestReturn::class => __('Investment return'),
            default => class_basename($row),
        };
    }

    /**
     * @param  list<string|null>  $parts
     */
    private function joinMeta(array $parts): string
    {
        return collect($parts)
            ->map(fn (mixed $part): string => trim((string) $part))
            ->filter(fn (string $part): bool => $part !== '')
            ->implode(' · ');
    }

    private function memberName(?Member $member, string $blank = '—'): string
    {
        if ($member === null) {
            return $blank;
        }

        $name = trim((string) $member->name);

        if ($name === '') {
            return $member->member_number ?: $blank;
        }

        return $name;
    }

    private function money(float $amount): string
    {
        return MoneyDisplay::format($amount) ?? number_format($amount, 2);
    }

    private function date(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $date = $value instanceof CarbonInterface ? $value : Carbon::parse((string) $value);

        return MemberDateDisplay::format($date, 'j M Y');
    }

    private function dateTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $date = $value instanceof CarbonInterface ? $value : Carbon::parse((string) $value);

        return MemberDateDisplay::format($date, 'j M Y H:i');
    }

    private function periodLabel(mixed $period): ?string
    {
        if ($period === null || $period === '') {
            return null;
        }

        $date = $period instanceof CarbonInterface ? $period : Carbon::parse((string) $period);

        return MemberDateDisplay::format($date, 'M Y');
    }
}
