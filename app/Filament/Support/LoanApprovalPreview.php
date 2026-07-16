<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Tenant\Account;
use App\Models\Tenant\FundTier;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanTier;
use App\Support\LoanFundExcessDisposition;
use App\Support\LoanFundingStrategy;
use App\Support\LoanSettings;
use Illuminate\Support\HtmlString;

final class LoanApprovalPreview
{
    public static function html(Loan $loan, float $previewApproved, bool $isEmergency): HtmlString
    {
        $loan->loadMissing('member');
        $fundBal = (float) ($loan->member->fundAccount?->balance ?? 0);
        $loanTier = LoanTier::forAmount($previewApproved);
        $threshold = LoanSettings::settlementThreshold();
        $currency = config('app.currency', 'SAR');

        if ($loanTier === null) {
            return new HtmlString(
                '<div class="rounded-lg border border-danger-200 bg-danger-50 px-4 py-3 text-sm text-danger-700 dark:border-danger-500/30 dark:bg-danger-500/10 dark:text-danger-400">'
                .e(__('No loan tier covers :amount. Adjust loan tiers before approving.', ['amount' => MoneyDisplay::format($previewApproved, $currency) ?? '']))
                .'</div>'
            );
        }

        $minInstall = (float) $loanTier->min_monthly_installment;
        $strategy = LoanFundingStrategy::normalize($loan->funding_strategy);
        $portions = LoanSettings::resolveFundingPortions($previewApproved, $fundBal, $strategy);
        $memberPortion = $portions['member_portion'];
        $masterPortion = $portions['master_portion'];
        $settleAmt = $previewApproved * $threshold;
        $count = Loan::computeInstallmentsCount($previewApproved, $fundBal, $minInstall, $threshold, $strategy);

        $fundTier = $isEmergency
            ? FundTier::emergency()
            : FundTier::forLoanTier($loanTier->id);

        if ($fundTier === null && ! $isEmergency) {
            return new HtmlString(
                '<div class="rounded-lg border border-warning-200 bg-warning-50 px-4 py-3 text-sm text-warning-800 dark:border-warning-500/30 dark:bg-warning-500/10 dark:text-warning-400">'
                .e(__('Loan tier ":tier" is not linked to an active fund pool. Link it on Fund tiers before approving.', [
                    'tier' => $loanTier->label,
                ]))
                .'</div>'
            );
        }

        $fundTierLabel = $fundTier
            ? $fundTier->label.' ('.number_format((float) $fundTier->available_amount, 2).' '.__('available').')'
            : __('No matching fund tier');

        $declaredPool = $fundTier ? (float) $fundTier->allocated_amount : 0.0;
        $masterFundBal = (float) (Account::masterFund()?->balance ?? 0);

        $rows = [
            [__('Loan tier'), $loanTier->label],
            [__('Fund tier'), $fundTierLabel],
            [__('Fund tier pool'), MoneyDisplay::format($declaredPool, $currency) ?? ''],
            [__('Master fund balance'), MoneyDisplay::format($masterFundBal, $currency) ?? ''],
            [__('Funding strategy'), LoanFundingStrategy::options()[$strategy] ?? $strategy],
        ];

        if ($strategy === LoanFundingStrategy::SPLIT_PERCENTAGE) {
            $rows[] = [__('Remaining fund balance'), LoanFundExcessDisposition::labelFromCashOutFlag((bool) $loan->cash_out_excess_fund)];
        }

        $rows = array_merge($rows, [
            [__('Member fund balance'), MoneyDisplay::format($fundBal, $currency) ?? ''],
            [__('Member portion'), MoneyDisplay::format($memberPortion, $currency) ?? ''],
            [__('Master portion'), MoneyDisplay::format($masterPortion, $currency) ?? ''],
            [__('Settlement top-up (:pct%)', ['pct' => $threshold * 100]), MoneyDisplay::format($settleAmt, $currency) ?? ''],
            [__('Monthly installment'), MoneyDisplay::format($minInstall, $currency) ?? ''],
            [__('Repayment period'), __(':count months', ['count' => $count])],
        ]);

        $body = '';
        foreach ($rows as [$label, $value]) {
            $valueCell = is_numeric($value)
                ? (MoneyDisplay::html((float) $value, $currency)?->toHtml() ?? e('—'))
                : (MoneyDisplay::markupForDisplay(is_string($value) ? $value : (string) $value, $currency));

            $body .= '<tr class="border-b border-gray-100 dark:border-white/10">'
                .'<td class="py-2 pl-3 pr-3 text-gray-500 dark:text-gray-400">'.e($label).'</td>'
                .'<td class="py-2 pe-3 text-end tabular-nums text-gray-900 dark:text-white">'.$valueCell.'</td>'
                .'</tr>';
        }

        $warn = '';
        if ($fundTier && $declaredPool + 0.01 < $previewApproved) {
            $warn = '<div class="rounded-lg border border-warning-200 bg-warning-50 px-4 py-3 text-sm text-warning-700 dark:border-warning-500/30 dark:bg-warning-500/10">'
                .e(__('Approved amount exceeds this fund tier declared pool. Partial disbursements apply until fully funded.'))
                .'</div>';
        }

        $table = '<div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">'
            .'<table class="w-full text-sm"><tbody>'.$body.'</tbody></table></div>';

        return new HtmlString('<div class="space-y-3">'.$warn.$table.'</div>');
    }
}
