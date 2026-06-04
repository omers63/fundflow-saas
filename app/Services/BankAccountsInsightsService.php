<?php

declare(strict_types=1);

namespace App\Services;

use App\Filament\Tenant\Resources\BankAccounts\BankAccountsResource;
use App\Filament\Tenant\Resources\FundPostings\FundPostingResource;
use App\Filament\Tenant\Resources\MasterAccounts\MasterAccountResource;
use App\Models\Tenant\Account;
use App\Models\Tenant\BankStatement;
use App\Models\Tenant\BankTemplate;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\FundPosting;
use App\Support\BusinessDay;
use App\Support\Insights\DualProgressTrendBuilder;
use App\Support\Insights\InsightFormatter;
use App\Support\Insights\InsightKpi;
use Carbon\Carbon;

final class BankAccountsInsightsService
{
    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $currency = InsightFormatter::currency();
        $activeTab = BankAccountsResource::resolveListBankAccountsTab();
        $now = BusinessDay::now();
        $bankClearing = app(BankClearingMatchService::class);

        $statementLineQuery = $bankClearing->applyRealBankStatementLinesScope(BankTransaction::query());

        $statusCounts = (clone $statementLineQuery)
            ->selectRaw('status, COUNT(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status');

        $imported = (int) ($statusCounts['imported'] ?? 0);
        $mirrored = (int) ($statusCounts['mirrored'] ?? 0);
        $posted = (int) ($statusCounts['posted'] ?? 0);
        $duplicate = (int) ($statusCounts['duplicate'] ?? 0);
        $ignored = (int) ($statusCounts['ignored'] ?? 0);
        $totalTx = $imported + $mirrored + $posted + $duplicate + $ignored;

        $pendingPost = $imported + $mirrored;
        $postRate = $totalTx > 0 ? round((($posted + $mirrored) / $totalTx) * 100, 1) : 0.0;
        $dupeRate = $totalTx > 0 ? round(($duplicate / $totalTx) * 100, 1) : 0.0;

        $unassignedCredits = (clone $statementLineQuery)
            ->where('status', 'imported')
            ->where('amount', '>', 0)
            ->whereNull('member_id')
            ->count();

        $pendingBankMatch = $bankClearing->pendingOperationalClearanceCount();

        $pendingFundPostings = FundPosting::query()->where('status', 'pending')->count();

        $templatesCount = BankTemplate::query()->count();
        $statementsThisMonth = BankStatement::query()
            ->whereMonth('imported_at', $now->month)
            ->whereYear('imported_at', $now->year)
            ->count();

        $failedStatements = BankStatement::query()->where('status', 'failed')->count();
        $processingStatements = BankStatement::query()->where('status', 'processing')->count();

        $masterCash = (float) (Account::masterCash()?->balance ?? 0);
        $masterBank = (float) (Account::masterBank()?->balance ?? 0);

        $since = BusinessDay::now()->subDays(30);
        $importedAmount = (float) (clone $statementLineQuery)
            ->where('status', 'imported')
            ->where('transaction_date', '>=', $since)
            ->sum('amount');

        $oldestMonth = $now->copy()->subMonths(5)->startOfMonth();
        $monthCounts = [];

        (clone $statementLineQuery)
            ->whereBetween('created_at', [$oldestMonth, $now->copy()->endOfMonth()])
            ->get(['created_at'])
            ->each(function (BankTransaction $transaction) use (&$monthCounts): void {
                $createdAt = $transaction->created_at;

                if ($createdAt === null) {
                    return;
                }

                $key = Carbon::parse((string) $createdAt)->startOfMonth()->format('Y-m');
                $monthCounts[$key] = ($monthCounts[$key] ?? 0) + 1;
            });

        $rawTrend = [];
        for ($i = 5; $i >= 0; $i--) {
            $month = $now->copy()->subMonths($i)->startOfMonth();
            $key = $month->format('Y-m');

            $rawTrend[] = [
                'label' => $month->locale(app()->getLocale())->translatedFormat('M'),
                'count' => $monthCounts[$key] ?? 0,
                'total' => $monthCounts[$key] ?? 0,
            ];
        }

        $trend = DualProgressTrendBuilder::mapCountTrend($rawTrend, 'total');

        $recentStatements = BankStatement::query()
            ->orderByDesc('imported_at')
            ->limit(5)
            ->get()
            ->map(fn (BankStatement $statement): array => [
                'id' => $statement->id,
                'filename' => $statement->filename,
                'bank_name' => $statement->bank_name,
                'status' => $statement->status,
                'imported_rows' => $statement->imported_rows,
                'total_rows' => $statement->total_rows,
                'url' => BankAccountsResource::getUrl('view', ['record' => $statement]),
                'date' => $statement->imported_at?->format('M j'),
            ])
            ->all();

        $indexUrl = BankAccountsResource::getUrl('index');

        return [
            'currency' => $currency,
            'active_tab' => $activeTab,
            'active_tab_label' => match ($activeTab) {
                'ledger' => __('Master bank ledger'),
                'statements' => __('Statements'),
                'clearance' => __('Pending bank match'),
                default => __('Statement lines'),
            },
            'pending_bank_match' => $pendingBankMatch,
            'pending_post' => $pendingPost,
            'unassigned_credits' => $unassignedCredits,
            'pending_fund_postings' => $pendingFundPostings,
            'failed_statements' => $failedStatements,
            'processing_statements' => $processingStatements,
            'templates_count' => $templatesCount,
            'statements_this_month' => $statementsThisMonth,
            'total_tx' => $totalTx,
            'post_rate' => $postRate,
            'dupe_rate' => $dupeRate,
            'master_cash' => $masterCash,
            'master_bank' => $masterBank,
            'imported_amount_30d' => $importedAmount,
            'trend' => $trend,
            'recent_statements' => $recentStatements,
            'status_breakdown' => [
                ['status' => 'imported', 'label' => __('Imported'), 'count' => $imported, 'color' => 'bg-amber-400'],
                ['status' => 'mirrored', 'label' => __('Mirrored'), 'count' => $mirrored, 'color' => 'bg-sky-500'],
                ['status' => 'posted', 'label' => __('Posted'), 'count' => $posted, 'color' => 'bg-emerald-500'],
                ['status' => 'duplicate', 'label' => __('Duplicate'), 'count' => $duplicate, 'color' => 'bg-rose-400'],
            ],
            'urls' => [
                'index' => $indexUrl,
                'imports' => BankAccountsResource::listUrl('imports'),
                'clearance' => BankAccountsResource::listUrl('clearance'),
                'ledger' => BankAccountsResource::listUrl('ledger'),
                'transactions' => BankAccountsResource::listUrl('imports'),
                'fund_postings' => FundPostingResource::listUrl(),
                'master_cash' => Account::masterCash()
                    ? MasterAccountResource::getUrl('view', ['record' => Account::masterCash()])
                    : MasterAccountResource::getUrl('index', ['tab' => 'cash']),
            ],
            'kpis' => InsightKpi::linkMany([
                [
                    'key' => 'pending',
                    'label' => __('To post'),
                    'value' => (string) $pendingPost,
                    'sub' => __('Imported + mirrored'),
                    'icon' => 'heroicon-o-clock',
                    'accent' => $pendingPost > 0 ? 'amber' : 'emerald',
                ],
                [
                    'key' => 'posted',
                    'label' => __('Posted'),
                    'value' => (string) $posted,
                    'sub' => InsightFormatter::percent($postRate),
                    'icon' => 'heroicon-o-check-circle',
                    'accent' => 'emerald',
                ],
                [
                    'key' => 'dupes',
                    'label' => __('Duplicates'),
                    'value' => (string) $duplicate,
                    'sub' => InsightFormatter::percent($dupeRate),
                    'icon' => 'heroicon-o-document-duplicate',
                    'accent' => $duplicate > 0 ? 'rose' : 'teal',
                ],
                [
                    'key' => 'templates',
                    'label' => __('Templates'),
                    'value' => (string) $templatesCount,
                    'sub' => __('Bank formats'),
                    'icon' => 'heroicon-o-document-text',
                    'accent' => 'indigo',
                ],
                [
                    'key' => 'statements',
                    'label' => __('Imports'),
                    'value' => (string) $statementsThisMonth,
                    'sub' => __('This month'),
                    'icon' => 'heroicon-o-arrow-up-tray',
                    'accent' => 'violet',
                ],
                [
                    'key' => 'unassigned',
                    'label' => __('Unassigned'),
                    'value' => (string) $unassignedCredits,
                    'sub' => __('Credits'),
                    'icon' => 'heroicon-o-user-minus',
                    'accent' => $unassignedCredits > 0 ? 'amber' : 'teal',
                ],
            ], [
                'pending' => BankAccountsResource::listUrl('imports', ['status' => ['value' => 'imported']]),
                'posted' => BankAccountsResource::listUrl('imports', ['status' => ['value' => 'posted']]),
                'dupes' => BankAccountsResource::listUrl('imports', ['status' => ['value' => 'duplicate']]),
                'templates' => BankAccountsResource::listUrl('statements'),
                'statements' => BankAccountsResource::listUrl('statements'),
                'unassigned' => BankAccountsResource::listUrl('imports'),
            ]),
            'hero' => $this->buildHero(
                $pendingPost,
                $unassignedCredits,
                $pendingFundPostings,
                $pendingBankMatch,
                $failedStatements,
                $indexUrl,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildHero(
        int $pendingPost,
        int $unassignedCredits,
        int $pendingFundPostings,
        int $pendingBankMatch,
        int $failedStatements,
        string $indexUrl,
    ): array {
        if ($failedStatements > 0) {
            return [
                'tone' => 'danger',
                'title' => __('Import failures need review'),
                'subtitle' => trans_choice(':count failed statement|:count failed statements', $failedStatements, [
                    'count' => $failedStatements,
                ]),
                'cta_label' => __('Statements'),
                'cta_url' => BankAccountsResource::listUrl('statements'),
            ];
        }

        if ($pendingPost > 0 || $unassignedCredits > 0 || $pendingBankMatch > 0) {
            return [
                'tone' => 'warning',
                'title' => __('Bank queue needs action'),
                'subtitle' => collect([
                    $pendingPost > 0
                    ? trans_choice(':count txn to post|:count txns to post', $pendingPost, ['count' => $pendingPost])
                    : null,
                    $unassignedCredits > 0
                    ? trans_choice(':count unassigned credit|:count unassigned credits', $unassignedCredits, ['count' => $unassignedCredits])
                    : null,
                    $pendingBankMatch > 0
                    ? trans_choice(':count awaiting bank match|:count awaiting bank match', $pendingBankMatch, ['count' => $pendingBankMatch])
                    : null,
                    $pendingFundPostings > 0
                    ? trans_choice(':count deposit pending|:count deposits pending', $pendingFundPostings, ['count' => $pendingFundPostings])
                    : null,
                ])->filter()->implode(' · '),
                'cta_label' => $pendingBankMatch > 0 ? __('Pending bank match') : __('Statement lines'),
                'cta_url' => $pendingBankMatch > 0
                    ? BankAccountsResource::listUrl('clearance')
                    : BankAccountsResource::listUrl('imports'),
            ];
        }

        return [
            'tone' => 'success',
            'title' => __('Banking is up to date'),
            'subtitle' => __('Imports are posted and the queue is clear.'),
            'cta_label' => __('Import statement'),
            'cta_url' => BankAccountsResource::listUrl('statements'),
        ];
    }
}
