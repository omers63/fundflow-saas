<?php

declare(strict_types=1);

namespace App\Support\Reconciliation;

use App\Filament\Support\MoneyDisplay;
use App\Filament\Tenant\Resources\Accounts\AccountResource;
use App\Filament\Tenant\Resources\BankAccounts\BankAccountsResource;
use App\Filament\Tenant\Resources\Contributions\ContributionResource;
use App\Filament\Tenant\Resources\Loans\LoanResource;
use App\Filament\Tenant\Resources\Members\MemberResource;
use App\Filament\Tenant\Support\BankClearingTabRegistry;
use App\Models\Tenant\Member;
use App\Models\Tenant\ReconciliationException;
use Illuminate\Support\HtmlString;

final class ReconciliationExceptionPresenter
{
    /**
     * @return array<string, string>
     */
    public static function domainLabels(): array
    {
        return [
            'master_account' => __('Master pool'),
            'bank_clearing' => __('Bank clearing'),
            'contribution' => __('Collections'),
            'loan' => __('Loans'),
            'emi' => __('Loan repayments'),
            'late_fee' => __('Late fees'),
        ];
    }

    public static function title(ReconciliationException $record): string
    {
        return self::codeTitles()[$record->exception_code] ?? $record->exception_code;
    }

    public static function summary(ReconciliationException $record): string
    {
        return self::codeSummaries()[$record->exception_code]
            ?? __('Review the affected records and post a correction or resolve when the variance is explained.');
    }

    public static function domainLabel(string $domain): string
    {
        return self::domainLabels()[$domain] ?? ucfirst(str_replace('_', ' ', $domain));
    }

    public static function recommendedAction(ReconciliationException $record): string
    {
        if (! self::isActionable($record)) {
            return __('This item is closed. Open History to review how it was resolved.');
        }

        return match ($record->exception_code) {
            'RECON_AMBIGUOUS_MATCH' => __('Pick the correct pending line, or clear the match from Bank Clearing.'),
            'RECON_UNMATCHED_BANK_LINE', 'UNMATCHED_CASH_ENTRY', 'STALE_PENDING' => __('Open Bank Clearing and match or clear the pending line.'),
            'CASH_DEPOSIT_UNBANKED' => __('Accept the deposit bank line or link the member deposit in Bank Clearing.'),
            'AMOUNT_MISMATCH' => __('Compare the posting and bank amounts, then correct the ledger or rematch.'),
            'EMI_OVER_COLLECTION' => __('Refund the excess EMI collection or accept the variance with supervisor sign-off.'),
            'FEE_WRONG_TIER', 'REPLACEMENT_PRIOR_TIER_NOT_REVERSED' => __('Re-apply the correct late fee tier or accept the tier judgment.'),
            'MASTER_CASH_POOL_DRIFT', 'MASTER_FUND_POOL_DRIFT', 'MEMBER_CASH_DRIFT', 'MEMBER_FUND_DRIFT' => __('Inspect master and member pool balances, then post a mirror correction if needed.'),
            default => __('Try auto-resolve first. If it still fails, post a targeted correction or resolve with notes.'),
        };
    }

    public static function isBankClearingRelated(ReconciliationException $record): bool
    {
        return $record->domain === 'bank_clearing'
            || in_array($record->exception_code, [
                'RECON_AMBIGUOUS_MATCH',
                'RECON_UNMATCHED_BANK_LINE',
                'UNMATCHED_CASH_ENTRY',
                'STALE_PENDING',
                'CASH_DEPOSIT_UNBANKED',
                'AMOUNT_MISMATCH',
            ], true);
    }

    public static function bankClearingUrl(?ReconciliationException $record = null): string
    {
        $queueFilter = BankClearingTabRegistry::FILTER_OPERATIONS;

        if (
            $record !== null && in_array($record->exception_code, [
                'RECON_UNMATCHED_BANK_LINE',
                'STALE_PENDING',
            ], true)
        ) {
            $queueFilter = BankClearingTabRegistry::FILTER_BANK_FILE;
        }

        return BankAccountsResource::listUrl(
            BankClearingTabRegistry::TAB_QUEUE,
            queueFilter: $queueFilter,
        );
    }

    /**
     * @return list<array{label: string, value: string, url: ?string}>
     */
    public static function contextItems(ReconciliationException $record): array
    {
        $entities = $record->affected_entities ?? [];
        $items = [];

        if (filled($entities['member_id'] ?? null)) {
            $memberId = (int) $entities['member_id'];
            $member = Member::query()->find($memberId);
            $items[] = [
                'label' => __('Member'),
                'value' => $member?->name ?? __('Member #:id', ['id' => $memberId]),
                'url' => MemberResource::getUrl('view', ['record' => $memberId]),
            ];
        }

        if (filled($entities['loan_id'] ?? null)) {
            $loanId = (int) $entities['loan_id'];
            $items[] = [
                'label' => __('Loan'),
                'value' => __('Loan #:id', ['id' => $loanId]),
                'url' => LoanResource::getUrl('view', ['record' => $loanId]),
            ];
        }

        if (filled($entities['contribution_id'] ?? null)) {
            $contributionId = (int) $entities['contribution_id'];
            $items[] = [
                'label' => __('Contribution'),
                'value' => __('Contribution #:id', ['id' => $contributionId]),
                'url' => ContributionResource::getUrl('edit', ['record' => $contributionId]),
            ];
        }

        if (filled($entities['account_id'] ?? null)) {
            $accountId = (int) $entities['account_id'];
            $items[] = [
                'label' => __('Account'),
                'value' => __('Account #:id', ['id' => $accountId]),
                'url' => AccountResource::getUrl('view', ['record' => $accountId]),
            ];
        }

        if (filled($entities['transaction_id'] ?? null)) {
            $items[] = [
                'label' => __('Transaction'),
                'value' => '#'.$entities['transaction_id'],
                'url' => null,
            ];
        }

        foreach (['bank_transaction_id', 'imported_bank_transaction_id', 'uncleared_bank_transaction_id'] as $key) {
            if (! filled($entities[$key] ?? null)) {
                continue;
            }

            $items[] = [
                'label' => __('Bank line'),
                'value' => '#'.$entities[$key],
                'url' => self::bankClearingUrl($record),
            ];
        }

        if (filled($entities['fund_posting_id'] ?? null)) {
            $items[] = [
                'label' => __('Deposit posting'),
                'value' => '#'.$entities['fund_posting_id'],
                'url' => null,
            ];
        }

        if (filled($entities['candidate_ids'] ?? null) && is_array($entities['candidate_ids'])) {
            $items[] = [
                'label' => __('Match candidates'),
                'value' => trans_choice(':count pending line|:count pending lines', count($entities['candidate_ids']), [
                    'count' => count($entities['candidate_ids']),
                ]),
                'url' => self::bankClearingUrl($record),
            ];
        }

        foreach ([
            'master_cash' => __('Master cash'),
            'master_fund' => __('Master fund'),
            'member_cash_sum' => __('Member cash total'),
            'member_fund_sum' => __('Member fund total'),
        ] as $key => $label) {
            if (! array_key_exists($key, $entities)) {
                continue;
            }

            $items[] = [
                'label' => $label,
                'value' => MoneyDisplay::format((float) $entities[$key]),
                'url' => null,
            ];
        }

        if ($items === [] && $entities !== []) {
            foreach ($entities as $key => $value) {
                if (is_array($value)) {
                    $value = json_encode($value, JSON_UNESCAPED_UNICODE);
                }

                $items[] = [
                    'label' => ucfirst(str_replace('_', ' ', (string) $key)),
                    'value' => (string) $value,
                    'url' => null,
                ];
            }
        }

        return $items;
    }

    public static function contextHtml(ReconciliationException $record): HtmlString
    {
        $items = self::contextItems($record);

        if ($items === []) {
            return new HtmlString(
                '<p class="text-sm text-gray-500 dark:text-gray-400">'.e(__('No linked records were captured for this exception.')).'</p>'
            );
        }

        $rows = '';

        foreach ($items as $item) {
            $value = filled($item['url'] ?? null)
                ? '<a href="'.e($item['url']).'" class="font-semibold text-primary-600 hover:underline dark:text-primary-400">'.e($item['value']).'</a>'
                : '<span class="font-medium text-gray-900 dark:text-white">'.e($item['value']).'</span>';

            $rows .= '<div class="flex flex-col gap-0.5 sm:flex-row sm:items-center sm:justify-between">'
                .'<dt class="text-xs font-medium text-gray-500 dark:text-gray-400">'.e($item['label']).'</dt>'
                .'<dd class="text-sm">'.$value.'</dd>'
                .'</div>';
        }

        $bankLink = self::isBankClearingRelated($record)
            ? '<div class="mt-4">'
            .'<a href="'.e(self::bankClearingUrl($record)).'" class="inline-flex items-center gap-1.5 text-sm font-semibold text-primary-600 hover:underline dark:text-primary-400">'
            .e(__('Open bank clearing workspace'))
            .'</a></div>'
            : '';

        return new HtmlString(
            '<dl class="ff-recon-context space-y-3">'.$rows.'</dl>'.$bankLink
        );
    }

    public static function isActionable(ReconciliationException $record): bool
    {
        return in_array($record->status, [
            ReconciliationException::STATUS_OPEN,
            ReconciliationException::STATUS_ESCALATED,
        ], true);
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            ReconciliationException::STATUS_OPEN => __('Open'),
            ReconciliationException::STATUS_ESCALATED => __('Escalated'),
            ReconciliationException::STATUS_RESOLVED => __('Resolved'),
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }

    /**
     * @return array{badge: string, banner: string, text: string}
     */
    public static function severityStyle(string $severity): array
    {
        return match ($severity) {
            'critical' => [
                'badge' => 'danger',
                'banner' => 'border-red-200 bg-red-50/90 dark:border-red-500/30 dark:bg-red-950/30',
                'text' => 'text-red-800 dark:text-red-200',
            ],
            'high' => [
                'badge' => 'warning',
                'banner' => 'border-amber-200 bg-amber-50/90 dark:border-amber-500/30 dark:bg-amber-950/30',
                'text' => 'text-amber-900 dark:text-amber-200',
            ],
            'medium' => [
                'badge' => 'info',
                'banner' => 'border-sky-200 bg-sky-50/90 dark:border-sky-500/30 dark:bg-sky-950/30',
                'text' => 'text-sky-900 dark:text-sky-200',
            ],
            default => [
                'badge' => 'gray',
                'banner' => 'border-gray-200 bg-gray-50/90 dark:border-white/10 dark:bg-white/5',
                'text' => 'text-gray-800 dark:text-gray-200',
            ],
        };
    }

    /**
     * @return list<string>
     */
    public static function contextPreview(ReconciliationException $record, int $limit = 2): array
    {
        return collect(self::contextItems($record))
            ->take($limit)
            ->map(fn (array $item): string => $item['label'].': '.$item['value'])
            ->values()
            ->all();
    }

    /**
     * @return list<array{type: string, name?: string, label: string, icon: string, color: string, url?: string}>
     */
    public static function recommendedFixActions(ReconciliationException $record, bool $advancedUi = false): array
    {
        if (! self::isActionable($record)) {
            return [];
        }

        $actions = [];

        if (self::isBankClearingRelated($record)) {
            $actions[] = [
                'type' => 'link',
                'label' => __('Open bank clearing'),
                'icon' => 'heroicon-o-building-library',
                'color' => 'primary',
                'url' => self::bankClearingUrl($record),
            ];
        }

        $action = match ($record->exception_code) {
            'RECON_AMBIGUOUS_MATCH' => [
                'type' => 'action',
                'name' => 'resolveAmbiguousBankMatch',
                'label' => __('Resolve bank match'),
                'icon' => 'heroicon-o-link',
                'color' => 'primary',
            ],
            'EMI_OVER_COLLECTION' => [
                'type' => 'action',
                'name' => 'postEmiOverpaymentRefund',
                'label' => __('Refund EMI overpayment'),
                'icon' => 'heroicon-o-arrow-uturn-left',
                'color' => 'warning',
            ],
            'FEE_WRONG_TIER', 'REPLACEMENT_PRIOR_TIER_NOT_REVERSED' => [
                'type' => 'action',
                'name' => 'postCorrectionEntry',
                'label' => __('Post correction entry'),
                'icon' => 'heroicon-o-document-plus',
                'color' => 'primary',
            ],
            'MASTER_CASH_POOL_DRIFT', 'MASTER_FUND_POOL_DRIFT', 'MEMBER_CASH_DRIFT', 'MEMBER_FUND_DRIFT' => [
                'type' => 'action',
                'name' => 'postCashCorrection',
                'label' => __('Post cash correction'),
                'icon' => 'heroicon-o-banknotes',
                'color' => 'primary',
            ],
            default => null,
        };

        if (is_array($action)) {
            $actions[] = $action;
        }

        if ($record->exception_code === 'EMI_OVER_COLLECTION') {
            $actions[] = [
                'type' => 'action',
                'name' => 'acceptOverride',
                'label' => __('Accept without correction'),
                'icon' => 'heroicon-o-hand-thumb-up',
                'color' => 'warning',
            ];
        }

        if (in_array($record->exception_code, ['FEE_WRONG_TIER', 'REPLACEMENT_PRIOR_TIER_NOT_REVERSED'], true)) {
            $actions[] = [
                'type' => 'action',
                'name' => 'acceptOverride',
                'label' => __('Accept tier judgment'),
                'icon' => 'heroicon-o-hand-thumb-up',
                'color' => 'warning',
            ];
        }

        $actions[] = [
            'type' => 'action',
            'name' => 'retryAutoResolve',
            'label' => __('Retry auto-resolve'),
            'icon' => 'heroicon-o-arrow-path',
            'color' => 'info',
        ];

        $resolveName = $advancedUi ? 'resolve' : 'primaryResolve';
        if (! self::isBankClearingRelated($record) || $advancedUi) {
            $actions[] = [
                'type' => 'action',
                'name' => $resolveName,
                'label' => $advancedUi ? __('Resolve') : __('Resolve with notes'),
                'icon' => 'heroicon-o-check',
                'color' => 'success',
            ];
        }

        $actions[] = [
            'type' => 'action',
            'name' => 'viewException',
            'label' => __('Full details'),
            'icon' => 'heroicon-o-document-magnifying-glass',
            'color' => 'gray',
        ];

        return $actions;
    }

    /**
     * @return array<string, string>
     */
    private static function codeTitles(): array
    {
        return [
            'MASTER_IMBALANCE_UNRESOLVED' => __('Master pool imbalance'),
            'UNBALANCED_ENTRY' => __('Unbalanced ledger entry'),
            'MASTER_CASH_POOL_DRIFT' => __('Master cash pool drift'),
            'MASTER_FUND_POOL_DRIFT' => __('Master fund pool drift'),
            'MEMBER_FUND_DRIFT' => __('Member fund drift'),
            'MEMBER_CASH_DRIFT' => __('Member cash drift'),
            'RECON_AUTO_FEE_EXEMPTION_REVERSAL' => __('Fee exemption reversal'),
            'CONTRIBUTION_EXEMPT_COLLECTED' => __('Exempt member collected'),
            'COLLECTED_WITHOUT_POST' => __('Collected without ledger post'),
            'PENDING_PAST_WINDOW_CLOSE' => __('Pending contribution past window'),
            'DUPLICATE_CONTRIBUTION_DEBIT' => __('Duplicate contribution debit'),
            'ORPHAN_MASTER_FUND_CREDIT' => __('Orphan master fund credit'),
            'CONTRIBUTION_MISSING_MASTER_CREDIT' => __('Contribution missing master credit'),
            'CONTRIBUTION_MEMBER_FUND_MISSING' => __('Contribution missing member fund leg'),
            'CONTRIBUTION_AMOUNT_MISMATCH' => __('Contribution amount mismatch'),
            'ACTIVE_BEFORE_FULL_DISBURSE' => __('Loan active before full disbursement'),
            'GRACE_CYCLE_EMI_DEBIT' => __('Grace cycle EMI debit'),
            'EMI_OVERDUE_WITHOUT_CLOCK' => __('Overdue EMI without clock'),
            'DISBURSEMENT_MEMBER_CASH_MISSING' => __('Disbursement missing member cash'),
            'EMI_OVER_COLLECTION' => __('EMI over-collection'),
            'EMI_COLLECTED_LEDGER_MISSING' => __('EMI collected, ledger missing'),
            'EMI_MISSED_SUFFICIENT_CASH' => __('Missed EMI with sufficient cash'),
            'GUARANTOR_BORROWER_DUPLICATE_DEBIT' => __('Duplicate guarantor/borrower debit'),
            'SCHEDULE_BEFORE_FULL_DISBURSE' => __('Schedule before full disbursement'),
            'FUND_TIER_OVER_COMMITTED' => __('Fund tier over-committed'),
            'RECON_AMBIGUOUS_MATCH' => __('Ambiguous bank match'),
            'RECON_UNMATCHED_BANK_LINE' => __('Unmatched bank import line'),
            'UNMATCHED_CASH_ENTRY' => __('Unmatched deposit line'),
            'STALE_PENDING' => __('Stale pending bank line'),
            'CASH_DEPOSIT_UNBANKED' => __('Accepted deposit not banked'),
            'AMOUNT_MISMATCH' => __('Bank amount mismatch'),
            'FEE_INCOME_DRIFT' => __('Fee income drift'),
            'FEE_POSTED_WRONG_ACCOUNT' => __('Fee posted to wrong account'),
            'FEE_WRONG_TIER' => __('Incorrect late fee tier'),
            'REPLACEMENT_PRIOR_TIER_NOT_REVERSED' => __('Prior tier not reversed'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function codeSummaries(): array
    {
        return [
            'MASTER_CASH_POOL_DRIFT' => __('Master cash no longer equals the sum of member cash balances.'),
            'MASTER_FUND_POOL_DRIFT' => __('Master fund pool no longer equals the sum of member fund balances.'),
            'MEMBER_CASH_DRIFT' => __('This member’s cash ledger components do not reconcile to the account balance.'),
            'MEMBER_FUND_DRIFT' => __('This member’s fund ledger components do not reconcile to the account balance.'),
            'RECON_AMBIGUOUS_MATCH' => __('An imported bank line matches more than one pending operational entry.'),
            'RECON_UNMATCHED_BANK_LINE' => __('An imported bank statement line has no matching pending entry.'),
            'UNMATCHED_CASH_ENTRY' => __('A pending deposit line has stayed uncleared beyond the stale threshold.'),
            'STALE_PENDING' => __('A pending operational bank line is older than the stale threshold.'),
            'CASH_DEPOSIT_UNBANKED' => __('A member deposit was accepted in the ledger but has no bank line yet.'),
            'AMOUNT_MISMATCH' => __('A linked deposit posting and bank line disagree on amount.'),
            'EMI_OVER_COLLECTION' => __('More was collected on an EMI than the installment due.'),
            'FEE_WRONG_TIER' => __('The posted late fee does not match the configured tier for this contribution.'),
            'PENDING_PAST_WINDOW_CLOSE' => __('A contribution is still pending collection after the cycle window closed.'),
        ];
    }
}
