<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Tenant\Contribution;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use Filament\Tables\Columns\TextColumn;

/**
 * Table column with the extra chips that sit beside a payment status: late fee, "Above limit" and
 * "Early settlement". The status column itself comes from {@see LateSettledArrearsTableStyling}.
 */
final class PaymentChipsColumn
{
    /** Loan-level chip: Early settlement (full / partial). */
    public static function forLoan(): TextColumn
    {
        return TextColumn::make('early_settlement')
            ->label(__('Early settlement'))
            ->badge()
            ->state(fn (Loan $record): ?string => LateSettledArrearsTableStyling::loanEarlySettlementChip($record)['label'] ?? null)
            ->sortable(query: function ($query, string $direction) {
                $shift = max(0, \App\Models\Tenant\Setting::contributionCycleStartDay() - 1);
                // 0 = full, 1 = partial, 2 = none — the same rules as loanEarlySettlementChip().
                return $query->orderByRaw("case
                    when loans.status = 'early_settled'
                        or exists (select 1 from loan_installments li where li.loan_id = loans.id and li.settled_via = 'early_full') then 0
                    when exists (select 1 from loan_installments li where li.loan_id = loans.id and (li.settled_via = 'early_partial' or li.waive_reason = 'early_skip'))
                        or exists (
                            select 1 from loan_installments li where li.loan_id = loans.id and li.settled_via is null and li.waive_reason is null
                                and li.status = 'paid' and li.paid_at is not null
                                and (year(date_sub(li.paid_at, interval {$shift} day)) * 12 + month(date_sub(li.paid_at, interval {$shift} day)))
                                    < (year(date_sub(li.due_date, interval {$shift} day)) * 12 + month(date_sub(li.due_date, interval {$shift} day)))) then 1
                    else 2 end {$direction}");
            })
            ->color('info')
            ->tooltip(fn (Loan $record): ?string => LateSettledArrearsTableStyling::loanEarlySettlementChip($record)['hint'] ?? null)
            ->placeholder('—')
            ->toggleable();
    }

    public static function make(): TextColumn
    {
        return TextColumn::make('payment_chips')
            ->label(__('Flags'))
            ->badge()
            ->state(fn (Contribution|LoanInstallment $record): array => array_column(LateSettledArrearsTableStyling::paymentChips($record), 'label'))
            ->color(function (string $state, Contribution|LoanInstallment $record): string {
                foreach (LateSettledArrearsTableStyling::paymentChips($record) as $chip) {
                    if ($chip['label'] === $state) {
                        return $chip['color'];
                    }
                }

                return 'gray';
            })
            ->tooltip(fn (Contribution|LoanInstallment $record): ?string => ($hints = array_column(LateSettledArrearsTableStyling::paymentChips($record), 'hint')) === []
                ? null
                : implode("\n", $hints))
            ->placeholder('—')
            ->wrap()
            ->toggleable();
    }
}
