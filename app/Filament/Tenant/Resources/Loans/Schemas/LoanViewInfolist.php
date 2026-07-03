<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\Loans\Schemas;

use App\Filament\Tenant\Resources\Members\MemberResource;
use App\Models\Tenant\Loan;
use App\Models\Tenant\Setting;
use App\Support\LoanFundExcessDisposition;
use App\Support\LoanFundingStrategy;
use App\Support\LoanSettings;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

final class LoanViewInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $currency = Setting::get('general', 'currency', 'USD');

        return $schema
            ->columns(1)
            ->schema([
                self::detailSection(__('Application & purpose'), __('Purpose and identifiers — amounts and progress are in the summary panel above.'))
                    ->columns(['default' => 1, 'md' => 2])
                    ->schema([
                        TextEntry::make('member.name')
                            ->label(__('Member'))
                            ->url(fn (Loan $record): ?string => $record->member_id
                                ? MemberResource::getUrl('view', ['record' => $record->member_id])
                                : null),
                        TextEntry::make('member.member_number')
                            ->label(__('Member number'))
                            ->placeholder(__('—')),
                        TextEntry::make('is_emergency')
                            ->label(__('Emergency loan'))
                            ->badge()
                            ->formatStateUsing(fn (bool $state): string => $state ? __('Yes') : __('No'))
                            ->color(fn (bool $state): string => $state ? 'danger' : 'gray'),
                        TextEntry::make('purpose')
                            ->label(__('Purpose'))
                            ->placeholder(__('—'))
                            ->columnSpanFull(),
                        TextEntry::make('rejection_reason')
                            ->label(__('Rejection reason'))
                            ->visible(fn (Loan $record): bool => $record->status === 'rejected')
                            ->color('danger')
                            ->columnSpanFull(),
                        TextEntry::make('cancellation_reason')
                            ->label(__('Cancellation reason'))
                            ->visible(fn (Loan $record): bool => $record->status === 'cancelled')
                            ->color('warning')
                            ->columnSpanFull(),
                    ]),
                self::detailSection(__('Timeline'), __('Key dates through the loan lifecycle'))
                    ->columns(['default' => 1, 'md' => 2, 'xl' => 3])
                    ->schema([
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
                        TextEntry::make('threshold_waived_at')
                            ->label(__('Threshold installments waived at'))
                            ->dateTime()
                            ->placeholder(__('—'))
                            ->visible(fn (Loan $record): bool => $record->threshold_waived_at !== null),
                        TextEntry::make('thresholdWaivedBy.name')
                            ->label(__('Threshold waiver by'))
                            ->placeholder(__('—'))
                            ->visible(fn (Loan $record): bool => $record->threshold_waived_at !== null),
                        TextEntry::make('threshold_waiver_reason')
                            ->label(__('Threshold waiver reason'))
                            ->placeholder(__('—'))
                            ->columnSpanFull()
                            ->visible(fn (Loan $record): bool => filled($record->threshold_waiver_reason)),
                    ]),
                self::detailSection(__('Application documents'), __('Signed forms submitted with the request'))
                    ->visible(fn (Loan $record): bool => filled($record->application_form_path))
                    ->schema([
                        TextEntry::make('application_form_path')
                            ->label(__('Signed loan application form'))
                            ->formatStateUsing(fn (?string $state): string => filled($state) ? __('Download') : __('—'))
                            ->url(fn (Loan $record): ?string => filled($record->application_form_path)
                                ? Storage::disk('public')->url((string) $record->application_form_path)
                                : null)
                            ->openUrlInNewTab(),
                    ]),
                self::detailSection(__('Funding'), __('How approved funds are sourced from member and master pools'))
                    ->columns(['default' => 1, 'md' => 2, 'xl' => 3])
                    ->visible(fn (Loan $record): bool => filled($record->funding_strategy)
                        || $record->loan_tier_id !== null
                        || $record->fund_tier_id !== null
                        || $record->amount_approved !== null)
                    ->schema([
                        TextEntry::make('funding_strategy')
                            ->label(__('Funding strategy'))
                            ->formatStateUsing(fn (?string $state): string => LoanFundingStrategy::options()[LoanFundingStrategy::normalize($state)] ?? __('—')),
                        TextEntry::make('cash_out_excess_fund')
                            ->label(__('Remaining fund balance'))
                            ->formatStateUsing(fn (bool $state, Loan $record): string => $record->funding_strategy === LoanFundingStrategy::SPLIT_PERCENTAGE
                                ? LoanFundExcessDisposition::labelFromCashOutFlag($state)
                                : __('—'))
                            ->visible(fn (Loan $record): bool => LoanFundingStrategy::normalize($record->funding_strategy) === LoanFundingStrategy::SPLIT_PERCENTAGE),
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
                    ]),
                self::detailSection(__('Repayment terms'), __('Scheduled collection and balance metrics'))
                    ->columns(['default' => 1, 'md' => 2, 'xl' => 3])
                    ->visible(fn (Loan $record): bool => $record->amount_approved !== null
                        || $record->installments_count !== null
                        || in_array($record->status, ['active', 'transferred', 'completed', 'early_settled', 'approved', 'partially_disbursed'], true))
                    ->schema([
                        TextEntry::make('grace_cycles')
                            ->label(__('Grace cycles before first EMI'))
                            ->formatStateUsing(fn (?int $state, Loan $record): string => match (true) {
                                $state !== null => LoanSettings::graceCycleLabel((int) $state),
                                $record->has_grace_cycle => LoanSettings::graceCycleLabel(1),
                                default => LoanSettings::graceCycleLabel(0),
                            }),
                        TextEntry::make('grace_period')
                            ->label(__('Contribution exemption period'))
                            ->state(fn (Loan $record): ?string => self::formatMonthYear($record->exempted_month, $record->exempted_year))
                            ->placeholder(__('—')),
                        TextEntry::make('first_repayment')
                            ->label(__('First repayment due'))
                            ->state(fn (Loan $record): ?string => self::formatMonthYear($record->first_repayment_month, $record->first_repayment_year))
                            ->placeholder(__('—')),
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
                        TextEntry::make('scheduled_outstanding')
                            ->label(__('Scheduled outstanding'))
                            ->state(fn (Loan $record): float => $record->getScheduledOutstanding())
                            ->money($currency)
                            ->visible(fn (Loan $record): bool => $record->getOutstandingBreakdown()['has_split']),
                        TextEntry::make('partial_repaid_ahead')
                            ->label(__('Partial paid'))
                            ->state(fn (Loan $record): float => $record->getPartialRepaymentAheadOfSchedule())
                            ->money($currency)
                            ->visible(fn (Loan $record): bool => $record->getOutstandingBreakdown()['has_split']),
                        TextEntry::make('ledger_outstanding')
                            ->label(__('Ledger outstanding'))
                            ->state(fn (Loan $record): float => $record->getLedgerOutstanding())
                            ->money($currency)
                            ->visible(fn (Loan $record): bool => $record->getOutstandingBreakdown()['has_split']),
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
                self::detailSection(__('Parties'), __('Guarantor and witness information'))
                    ->columns(['default' => 1, 'md' => 2])
                    ->visible(fn (Loan $record): bool => $record->guarantor_member_id !== null
                        || filled($record->guarantor_name)
                        || filled($record->witness1_name)
                        || filled($record->witness2_name))
                    ->schema([
                        TextEntry::make('guarantor.name')
                            ->label(__('Guarantor (matched member)'))
                            ->url(fn (Loan $record): ?string => $record->guarantor_member_id
                                ? MemberResource::getUrl('view', ['record' => $record->guarantor_member_id])
                                : null)
                            ->placeholder(__('—'))
                            ->visible(fn (Loan $record): bool => $record->guarantor_member_id !== null),
                        TextEntry::make('guarantor_name')
                            ->label(__('Guarantor name (from application)'))
                            ->placeholder(__('—'))
                            ->visible(fn (Loan $record): bool => filled($record->guarantor_name)
                                && $record->guarantor_member_id === null),
                        TextEntry::make('guarantor.member_number')
                            ->label(__('Guarantor member number'))
                            ->placeholder(__('—'))
                            ->visible(fn (Loan $record): bool => $record->guarantor_member_id !== null),
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
                            })
                            ->visible(fn (Loan $record): bool => $record->guarantor_member_id !== null),
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
                        TextEntry::make('witness1_name')
                            ->label(__('Witness 1 name'))
                            ->placeholder(__('—'))
                            ->visible(fn (Loan $record): bool => filled($record->witness1_name) || filled($record->witness1_phone)),
                        TextEntry::make('witness1_phone')
                            ->label(__('Witness 1 phone'))
                            ->placeholder(__('—'))
                            ->visible(fn (Loan $record): bool => filled($record->witness1_name) || filled($record->witness1_phone)),
                        TextEntry::make('witness2_name')
                            ->label(__('Witness 2 name'))
                            ->placeholder(__('—'))
                            ->visible(fn (Loan $record): bool => filled($record->witness2_name) || filled($record->witness2_phone)),
                        TextEntry::make('witness2_phone')
                            ->label(__('Witness 2 phone'))
                            ->placeholder(__('—'))
                            ->visible(fn (Loan $record): bool => filled($record->witness2_name) || filled($record->witness2_phone)),
                    ]),
            ]);
    }

    private static function detailSection(string $heading, ?string $description = null): Section
    {
        $section = Section::make($heading)
            ->compact()
            ->secondary();

        if ($description !== null) {
            $section->description($description);
        }

        return $section;
    }

    private static function formatMonthYear(?int $month, ?int $year): ?string
    {
        if ($month === null || $year === null) {
            return null;
        }

        return Carbon::create($year, $month, 1)->translatedFormat('F Y');
    }
}
