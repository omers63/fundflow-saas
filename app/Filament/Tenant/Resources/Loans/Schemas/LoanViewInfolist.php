<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\Loans\Schemas;

use App\Models\Tenant\Loan;
use App\Models\Tenant\Setting;
use App\Support\LoanFundingStrategy;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;

final class LoanViewInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $currency = Setting::get('general', 'currency', 'USD');

        return $schema->schema([
            Tabs::make('loanDetails')
                ->contained(false)
                ->columnSpanFull()
                ->persistTabInQueryString('loanTab')
                ->tabs([
                    Tab::make(__('Loan request'))
                        ->icon('heroicon-o-document-text')
                        ->schema([
                            Section::make(__('Application'))
                                ->columns(3)
                                ->schema([
                                    TextEntry::make('member.name')
                                        ->label(__('Member')),
                                    TextEntry::make('member.member_number')
                                        ->label(__('Member number'))
                                        ->placeholder(__('—')),
                                    TextEntry::make('status')
                                        ->badge()
                                        ->formatStateUsing(fn (string $state): string => Loan::statusOptions()[$state] ?? $state)
                                        ->color(fn (string $state): string => Loan::statusColor($state)),
                                    TextEntry::make('amount_requested')
                                        ->label(__('Amount requested'))
                                        ->money($currency),
                                    TextEntry::make('amount_approved')
                                        ->label(__('Amount approved'))
                                        ->money($currency)
                                        ->placeholder(__('—')),
                                    TextEntry::make('amount_disbursed')
                                        ->label(__('Amount disbursed'))
                                        ->money($currency),
                                    TextEntry::make('outstanding')
                                        ->label(__('Outstanding balance'))
                                        ->state(fn (Loan $record): float => $record->getOutstandingBalance())
                                        ->money($currency),
                                    TextEntry::make('is_emergency')
                                        ->label(__('Emergency loan'))
                                        ->badge()
                                        ->formatStateUsing(fn (bool $state): string => $state ? __('Yes') : __('No'))
                                        ->color(fn (bool $state): string => $state ? 'danger' : 'gray'),
                                    TextEntry::make('queue_position')
                                        ->label(__('Queue position'))
                                        ->placeholder(__('—')),
                                    TextEntry::make('applied_at')
                                        ->label(__('Applied at'))
                                        ->dateTime(),
                                    TextEntry::make('approved_at')
                                        ->label(__('Approved at'))
                                        ->dateTime()
                                        ->placeholder(__('—')),
                                    TextEntry::make('approvedBy.name')
                                        ->label(__('Approved by'))
                                        ->placeholder(__('—')),
                                    TextEntry::make('disbursed_at')
                                        ->label(__('Fully disbursed at'))
                                        ->dateTime()
                                        ->placeholder(__('—')),
                                    TextEntry::make('settled_at')
                                        ->label(__('Settled at'))
                                        ->dateTime()
                                        ->placeholder(__('—')),
                                    TextEntry::make('purpose')
                                        ->label(__('Purpose'))
                                        ->placeholder(__('—'))
                                        ->columnSpanFull(),
                                    TextEntry::make('rejection_reason')
                                        ->label(__('Rejection reason'))
                                        ->visible(fn (Loan $record): bool => $record->status === 'rejected')
                                        ->columnSpanFull(),
                                    TextEntry::make('cancellation_reason')
                                        ->label(__('Cancellation reason'))
                                        ->visible(fn (Loan $record): bool => $record->status === 'cancelled')
                                        ->columnSpanFull(),
                                ]),
                        ]),
                    Tab::make(__('Fund & schedule'))
                        ->icon('heroicon-o-calendar-days')
                        ->schema([
                            Section::make(__('Funding'))
                                ->columns(3)
                                ->schema([
                                    TextEntry::make('funding_strategy')
                                        ->label(__('Funding strategy'))
                                        ->formatStateUsing(fn (?string $state): string => LoanFundingStrategy::options()[LoanFundingStrategy::normalize($state)] ?? __('—')),
                                    TextEntry::make('cash_out_excess_fund')
                                        ->label(__('Cash out excess fund at disbursement'))
                                        ->formatStateUsing(fn (bool $state): string => $state ? __('Yes') : __('No')),
                                    TextEntry::make('loanTier.label')
                                        ->label(__('Loan tier (EMI)'))
                                        ->placeholder(__('—')),
                                    TextEntry::make('fundTier.label')
                                        ->label(__('Fund tier'))
                                        ->placeholder(__('—')),
                                    TextEntry::make('member_portion')
                                        ->label(__('Member fund portion'))
                                        ->money($currency)
                                        ->placeholder(__('—')),
                                    TextEntry::make('master_portion')
                                        ->label(__('Master fund portion'))
                                        ->money($currency)
                                        ->placeholder(__('—')),
                                    TextEntry::make('settlement_threshold')
                                        ->label(__('Settlement threshold'))
                                        ->formatStateUsing(function (?string $state, Loan $record): string {
                                            if ($state === null) {
                                                return __('—');
                                            }

                                            $approved = (float) ($record->amount_approved ?? 0);
                                            $amount = round($approved * (float) $state, 2);

                                            return number_format((float) $state * 100, 1).'% ('.number_format($amount, 2).')';
                                        }),
                                    TextEntry::make('disbursement_progress')
                                        ->label(__('Disbursement progress'))
                                        ->state(function (Loan $record): string {
                                            $approved = (float) ($record->amount_approved ?? $record->amount_requested ?? 0);
                                            $disbursed = (float) $record->amount_disbursed;
                                            $count = $record->disbursements_count ?? $record->disbursements()->count();

                                            if ($approved <= 0) {
                                                return __('—');
                                            }

                                            $percent = (int) round(($disbursed / $approved) * 100);

                                            return $count > 1
                                                ? __(':disbursed of :approved (:percent%) · :count tranches', [
                                                    'disbursed' => number_format($disbursed, 2),
                                                    'approved' => number_format($approved, 2),
                                                    'percent' => $percent,
                                                    'count' => $count,
                                                ])
                                                : __(':disbursed of :approved (:percent%)', [
                                                    'disbursed' => number_format($disbursed, 2),
                                                    'approved' => number_format($approved, 2),
                                                    'percent' => $percent,
                                                ]);
                                        }),
                                ]),
                            Section::make(__('Repayment schedule'))
                                ->columns(3)
                                ->schema([
                                    TextEntry::make('grace_cycles')
                                        ->label(__('Grace cycles before first EMI'))
                                        ->formatStateUsing(fn (?int $state, Loan $record): string => match (true) {
                                            $state !== null => trans_choice(':count cycle|:count cycles', (int) $state, ['count' => (int) $state]),
                                            $record->has_grace_cycle => __('One cycle'),
                                            default => __('None'),
                                        }),
                                    TextEntry::make('grace_period')
                                        ->label(__('Contribution exemption period'))
                                        ->state(fn (Loan $record): ?string => self::formatMonthYear($record->exempted_month, $record->exempted_year))
                                        ->placeholder(__('—')),
                                    TextEntry::make('first_repayment')
                                        ->label(__('First repayment due'))
                                        ->state(fn (Loan $record): ?string => self::formatMonthYear($record->first_repayment_month, $record->first_repayment_year))
                                        ->placeholder(__('—')),
                                    TextEntry::make('installments_count')
                                        ->label(__('Installments'))
                                        ->placeholder(__('—')),
                                    TextEntry::make('installment_progress')
                                        ->label(__('Installments paid'))
                                        ->state(function (Loan $record): string {
                                            $total = (int) ($record->installments_count ?? 0);

                                            if ($total <= 0) {
                                                return __('Not generated');
                                            }

                                            $paid = $record->relationLoaded('installments')
                                                ? $record->installments->where('status', 'paid')->count()
                                                : $record->installments()->where('status', 'paid')->count();

                                            return __(':paid of :total paid', [
                                                'paid' => $paid,
                                                'total' => $total,
                                            ]);
                                        }),
                                    TextEntry::make('representative_emi')
                                        ->label(__('Representative EMI'))
                                        ->state(fn (Loan $record): float => $record->representativeEmiAmount())
                                        ->money($currency)
                                        ->placeholder(__('—')),
                                    TextEntry::make('monthly_repayment')
                                        ->label(__('Scheduled monthly repayment'))
                                        ->money($currency)
                                        ->placeholder(__('—')),
                                    TextEntry::make('due_date')
                                        ->label(__('Final due date'))
                                        ->date()
                                        ->placeholder(__('—')),
                                    TextEntry::make('total_repaid')
                                        ->label(__('Total repaid'))
                                        ->money($currency),
                                    TextEntry::make('repaid_to_master')
                                        ->label(__('Repaid to master fund'))
                                        ->money($currency),
                                    TextEntry::make('full_repayment_threshold')
                                        ->label(__('Full repayment threshold'))
                                        ->state(fn (Loan $record): float => $record->fullRepaymentThreshold())
                                        ->money($currency),
                                    TextEntry::make('late_repayment_count')
                                        ->label(__('Late repayments'))
                                        ->visible(fn (Loan $record): bool => in_array($record->status, ['active', 'transferred', 'completed', 'early_settled'], true)),
                                    TextEntry::make('late_repayment_amount')
                                        ->label(__('Late repayment amount'))
                                        ->money($currency)
                                        ->visible(fn (Loan $record): bool => in_array($record->status, ['active', 'transferred', 'completed', 'early_settled'], true)),
                                ]),
                        ]),
                    Tab::make(__('Guarantor & witnesses'))
                        ->icon('heroicon-o-user-group')
                        ->schema([
                            Section::make(__('Guarantor'))
                                ->columns(2)
                                ->schema([
                                    TextEntry::make('guarantor.name')
                                        ->label(__('Guarantor'))
                                        ->placeholder(__('—')),
                                    TextEntry::make('guarantor.member_number')
                                        ->label(__('Guarantor member number'))
                                        ->placeholder(__('—')),
                                    TextEntry::make('guarantor_liability')
                                        ->label(__('Liability status'))
                                        ->state(function (Loan $record): string {
                                            if ($record->transferred_to_guarantor_at !== null) {
                                                return __('Transferred to guarantor');
                                            }

                                            if ($record->guarantor_liability_transferred_at !== null) {
                                                return __('Guarantor liability active');
                                            }

                                            if ($record->guarantor_member_id === null) {
                                                return __('No guarantor');
                                            }

                                            return __('Borrower (standard cycle)');
                                        })
                                        ->badge()
                                        ->color(fn (Loan $record): string => match (true) {
                                            $record->transferred_to_guarantor_at !== null => 'danger',
                                            $record->guarantor_liability_transferred_at !== null => 'warning',
                                            $record->guarantor_member_id === null => 'gray',
                                            default => 'info',
                                        }),
                                    TextEntry::make('guarantor_liability_transferred_at')
                                        ->label(__('Guarantor liability from'))
                                        ->dateTime()
                                        ->placeholder(__('—'))
                                        ->visible(fn (Loan $record): bool => $record->guarantor_liability_transferred_at !== null),
                                    TextEntry::make('transferred_to_guarantor_at')
                                        ->label(__('Loan transferred to guarantor'))
                                        ->dateTime()
                                        ->placeholder(__('—'))
                                        ->visible(fn (Loan $record): bool => $record->transferred_to_guarantor_at !== null),
                                    TextEntry::make('guarantor_released_at')
                                        ->label(__('Guarantor released'))
                                        ->dateTime()
                                        ->placeholder(__('—'))
                                        ->visible(fn (Loan $record): bool => $record->guarantor_released_at !== null),
                                ]),
                            Section::make(__('Witnesses'))
                                ->columns(2)
                                ->schema([
                                    TextEntry::make('witness1_name')
                                        ->label(__('Witness 1 name'))
                                        ->placeholder(__('—')),
                                    TextEntry::make('witness1_phone')
                                        ->label(__('Witness 1 phone'))
                                        ->placeholder(__('—')),
                                    TextEntry::make('witness2_name')
                                        ->label(__('Witness 2 name'))
                                        ->placeholder(__('—')),
                                    TextEntry::make('witness2_phone')
                                        ->label(__('Witness 2 phone'))
                                        ->placeholder(__('—')),
                                ]),
                        ]),
                ]),
        ]);
    }

    private static function formatMonthYear(?int $month, ?int $year): ?string
    {
        if ($month === null || $year === null) {
            return null;
        }

        return Carbon::create($year, $month, 1)->translatedFormat('F Y');
    }
}
