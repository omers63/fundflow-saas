<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Support\LoanFundExcessDisposition;
use App\Support\LoanFundingStrategy;
use App\Support\LoanSettings;
use Closure;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\HtmlString;

final class LoanApplicationFundingFields
{
    /**
     * @return array{Radio|Placeholder, Radio|null, Placeholder|null}
     */
    public static function components(
        Closure $memberResolver,
        string $amountField = 'amount',
    ): array {
        $currency = Setting::get('general', 'currency', 'USD');

        $strategyRadio = Radio::make('funding_strategy')
            ->label(__('How should this loan be funded?'))
            ->options(fn (): array => LoanFundingStrategy::availableOptions())
            ->default(fn (): string => LoanFundingStrategy::defaultForApplication())
            ->required()
            ->live()
            ->visible(fn (): bool => count(LoanFundingStrategy::availableOptions()) > 1);

        $strategyFixed = Placeholder::make('funding_strategy_fixed')
            ->label(__('How should this loan be funded?'))
            ->content(fn (): string => LoanFundingStrategy::availableOptions()[LoanFundingStrategy::defaultForApplication()] ?? '—')
            ->visible(fn (): bool => count(LoanFundingStrategy::availableOptions()) === 1);

        $excessDisposition = Radio::make('excess_fund_disposition')
            ->label(__('Remaining fund balance above your loan share'))
            ->options(fn (): array => LoanFundExcessDisposition::availableOptions())
            ->default(LoanFundExcessDisposition::defaultForApplication())
            ->required()
            ->live()
            ->helperText(function (Get $get) use ($memberResolver, $amountField, $currency): ?string {
                $amount = (float) ($get($amountField) ?? 0);
                $member = $memberResolver($get);

                if ($amount <= 0 || ! $member instanceof Member) {
                    return __('Choose whether excess fund stays in the fund account or moves to cash when the loan is disbursed.');
                }

                $excess = LoanSettings::excessFundCashOutAmount(
                    $amount,
                    $member->getFundBalance(),
                    LoanFundingStrategy::SPLIT_PERCENTAGE,
                );

                if ($excess <= 0) {
                    return __('No fund balance above the configured share for this amount.');
                }

                if (($get('excess_fund_disposition') ?? LoanFundExcessDisposition::KEEP_IN_FUND) === LoanFundExcessDisposition::CASH_OUT) {
                    return __('Estimated transfer at disbursement: :amount', [
                        'amount' => MoneyDisplay::format($excess, $currency) ?? '—',
                    ]);
                }

                return __('Estimated excess in fund account: :amount', [
                    'amount' => MoneyDisplay::format($excess, $currency) ?? '—',
                ]);
            })
            ->visible(function (Get $get) use ($memberResolver, $amountField): bool {
                if (($get('funding_strategy') ?? LoanFundingStrategy::defaultForApplication()) !== LoanFundingStrategy::SPLIT_PERCENTAGE) {
                    return false;
                }

                if (count(LoanFundExcessDisposition::availableOptions()) <= 1) {
                    return false;
                }

                $member = $memberResolver($get);
                $amount = (float) ($get($amountField) ?? 0);

                if ($amount <= 0 || ! $member instanceof Member) {
                    return true;
                }

                return LoanSettings::excessFundCashOutAmount(
                    $amount,
                    $member->getFundBalance(),
                    LoanFundingStrategy::SPLIT_PERCENTAGE,
                ) > 0;
            });

        $preview = Placeholder::make('funding_preview')
            ->label(__('Funding preview'))
            ->content(function (Get $get) use ($memberResolver, $amountField, $currency): HtmlString {
                $member = $memberResolver($get);
                $amount = (float) ($get($amountField) ?? 0);
                $strategy = (string) ($get('funding_strategy') ?? LoanFundingStrategy::defaultForApplication());

                if ($amount <= 0 || ! $member instanceof Member) {
                    return new HtmlString(
                        '<p class="text-sm text-gray-500 dark:text-gray-400">'.e(__('Enter a member and amount to preview the funding split.')).'</p>'
                    );
                }

                $fundBal = $member->getFundBalance();
                $portions = LoanSettings::resolveFundingPortions($amount, $fundBal, $strategy);
                $strategyLabel = LoanFundingStrategy::options()[$strategy]
                    ?? LoanFundingStrategy::availableOptions()[$strategy]
                    ?? $strategy;

                $rows = [
                    [__('Strategy'), $strategyLabel],
                    [__('Member fund balance'), MoneyDisplay::format($fundBal, $currency) ?? '—'],
                    [__('Member portion'), MoneyDisplay::format($portions['member_portion'], $currency) ?? '—'],
                    [__('Master portion'), MoneyDisplay::format($portions['master_portion'], $currency) ?? '—'],
                ];

                if ($strategy === LoanFundingStrategy::SPLIT_PERCENTAGE) {
                    $excess = LoanSettings::excessFundCashOutAmount($amount, $fundBal, $strategy);
                    $rows[] = [__('Excess fund above share'), MoneyDisplay::format($excess, $currency) ?? '—'];
                }

                $body = '';
                foreach ($rows as [$label, $value]) {
                    $body .= '<div class="flex items-center justify-between gap-3 border-b border-gray-100 py-1.5 last:border-0 dark:border-white/10">'
                        .'<span class="text-xs text-gray-500 dark:text-gray-400">'.e($label).'</span>'
                        .'<span class="text-xs font-semibold tabular-nums text-gray-900 dark:text-white">'.e($value).'</span>'
                        .'</div>';
                }

                return new HtmlString(
                    '<div class="rounded-lg border border-sky-200/80 bg-sky-50/60 px-3 py-1.5 dark:border-sky-800/40 dark:bg-sky-950/30">'.$body.'</div>'
                );
            })
            ->columnSpanFull();

        return [$strategyRadio, $strategyFixed, $excessDisposition, $preview];
    }
}
