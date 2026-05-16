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
use App\Support\Insights\InsightFormatter;
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
        $now = Carbon::now();

        $statusCounts = BankTransaction::query()
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

        $unassignedCredits = BankTransaction::query()
            ->where('status', 'imported')
            ->where('amount', '>', 0)
            ->whereNull('member_id')
            ->count();

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

        $since = Carbon::now()->subDays(30);
        $importedAmount = (float) BankTransaction::query()
            ->where('status', 'imported')
            ->where('transaction_date', '>=', $since)
            ->sum('amount');

        $trend = [];
        for ($i = 5; $i >= 0; $i--) {
            $month = $now->copy()->subMonths($i)->startOfMonth();
            $end = $month->copy()->endOfMonth();
            $count = BankTransaction::query()
                ->whereBetween('created_at', [$month, $end])
                ->count();

            $trend[] = [
                'label' => $month->locale(app()->getLocale())->translatedFormat('M'),
                'count' => $count,
            ];
        }

        $maxTrend = max(1, (int) collect($trend)->max('count'));

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
            'active_tab_label' => $activeTab === 'transactions' ? __('Transactions') : __('Statements'),
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
            'max_trend' => $maxTrend,
            'recent_statements' => $recentStatements,
            'status_breakdown' => [
                ['status' => 'imported', 'label' => __('Imported'), 'count' => $imported, 'color' => 'bg-amber-400'],
                ['status' => 'mirrored', 'label' => __('Mirrored'), 'count' => $mirrored, 'color' => 'bg-sky-500'],
                ['status' => 'posted', 'label' => __('Posted'), 'count' => $posted, 'color' => 'bg-emerald-500'],
                ['status' => 'duplicate', 'label' => __('Duplicate'), 'count' => $duplicate, 'color' => 'bg-rose-400'],
            ],
            'urls' => [
                'index' => $indexUrl,
                'transactions' => $indexUrl.'?tab=transactions',
                'fund_postings' => FundPostingResource::getUrl('index'),
                'master_cash' => Account::masterCash()
                    ? MasterAccountResource::getUrl('view', ['record' => Account::masterCash()])
                    : MasterAccountResource::getUrl('index', ['tab' => 'cash']),
            ],
            'kpis' => [
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
            ],
            'hero' => $this->buildHero(
                $pendingPost,
                $unassignedCredits,
                $pendingFundPostings,
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
                'cta_url' => $indexUrl,
            ];
        }

        if ($pendingPost > 0 || $unassignedCredits > 0) {
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
                    $pendingFundPostings > 0
                    ? trans_choice(':count fund posting|:count fund postings', $pendingFundPostings, ['count' => $pendingFundPostings])
                    : null,
                ])->filter()->implode(' · '),
                'cta_label' => __('Transactions'),
                'cta_url' => $indexUrl.'?tab=transactions',
            ];
        }

        return [
            'tone' => 'success',
            'title' => __('Banking is up to date'),
            'subtitle' => __('Imports are posted and the queue is clear.'),
            'cta_label' => __('Import statement'),
            'cta_url' => $indexUrl,
        ];
    }
}
