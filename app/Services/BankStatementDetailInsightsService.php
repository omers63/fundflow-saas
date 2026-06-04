<?php

declare(strict_types=1);

namespace App\Services;

use App\Filament\Tenant\Resources\BankAccounts\BankAccountsResource;
use App\Models\Tenant\BankStatement;
use App\Models\Tenant\BankTransaction;
use App\Support\BusinessDay;
use App\Support\Insights\InsightFormatter;
use Carbon\Carbon;
use Illuminate\Support\Str;

final class BankStatementDetailInsightsService
{
    /**
     * @return array<string, mixed>
     */
    public function snapshot(BankStatement $statement): array
    {
        $statement = $statement->fresh() ?? $statement;

        $statusCounts = BankTransaction::query()
            ->where('bank_statement_id', $statement->id)
            ->selectRaw('status, COUNT(*) as cnt, SUM(amount) as total_amount')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        $imported = (int) ($statusCounts['imported']->cnt ?? 0);
        $mirrored = (int) ($statusCounts['mirrored']->cnt ?? 0);
        $posted = (int) ($statusCounts['posted']->cnt ?? 0);
        $duplicate = (int) ($statusCounts['duplicate']->cnt ?? 0);
        $ignored = (int) ($statusCounts['ignored']->cnt ?? 0);
        $totalLines = $imported + $mirrored + $posted + $duplicate + $ignored;

        $pendingPost = $imported + $mirrored;
        $postRate = $totalLines > 0 ? round((($posted + $mirrored) / $totalLines) * 100, 1) : 0.0;

        $creditTotal = (float) BankTransaction::query()
            ->where('bank_statement_id', $statement->id)
            ->where('amount', '>', 0)
            ->sum('amount');

        $debitTotal = abs((float) BankTransaction::query()
            ->where('bank_statement_id', $statement->id)
            ->where('amount', '<', 0)
            ->sum('amount'));

        $unassigned = BankTransaction::query()
            ->where('bank_statement_id', $statement->id)
            ->where('status', 'imported')
            ->where('amount', '>', 0)
            ->whereNull('member_id')
            ->count();

        $sparklineCounts = [];
        $sparklineWindowStart = BusinessDay::now()->subDays(6)->startOfDay();
        $sparklineWindowEnd = BusinessDay::now()->endOfDay();

        BankTransaction::query()
            ->where('bank_statement_id', $statement->id)
            ->whereBetween('transaction_date', [$sparklineWindowStart, $sparklineWindowEnd])
            ->get(['transaction_date'])
            ->each(function (BankTransaction $transaction) use (&$sparklineCounts): void {
                $transactionDate = $transaction->transaction_date;

                if ($transactionDate === null) {
                    return;
                }

                $key = Carbon::parse((string) $transactionDate)->startOfDay()->toDateString();
                $sparklineCounts[$key] = ($sparklineCounts[$key] ?? 0) + 1;
            });

        $sparkline = [];
        for ($i = 6; $i >= 0; $i--) {
            $day = BusinessDay::now()->subDays($i)->startOfDay()->toDateString();
            $sparkline[] = $sparklineCounts[$day] ?? 0;
        }

        $recent = BankTransaction::query()
            ->where('bank_statement_id', $statement->id)
            ->with('member')
            ->orderByDesc('transaction_date')
            ->limit(5)
            ->get()
            ->map(fn (BankTransaction $line): array => [
                'date' => $line->transaction_date !== null
                    ? Carbon::parse((string) $line->transaction_date)->format('M j')
                    : null,
                'description' => Str::limit($line->description ?? '—', 40),
                'amount' => InsightFormatter::money((float) $line->amount),
                'status' => $line->status,
                'member' => $line->member?->name,
                'signed_class' => (float) $line->amount >= 0
                    ? 'text-emerald-600 dark:text-emerald-400'
                    : 'text-rose-600 dark:text-rose-400',
            ])
            ->all();

        $hero = match ($statement->status) {
            'failed' => [
                'tone' => 'danger',
                'title' => __('Import failed'),
                'subtitle' => $statement->notes ?: __('Review notes and re-import if needed.'),
            ],
            'processing' => [
                'tone' => 'amber',
                'title' => __('Processing import'),
                'subtitle' => __('Statement lines are being parsed.'),
            ],
            'pending' => [
                'tone' => 'amber',
                'title' => __('Awaiting import'),
                'subtitle' => __('This statement has not finished importing yet.'),
            ],
            default => $pendingPost > 0
            ? [
                'tone' => 'warning',
                'title' => __('Lines ready to post'),
                'subtitle' => trans_choice(':count line needs ledger posting|:count lines need ledger posting', $pendingPost, ['count' => $pendingPost]),
                'cta_label' => __('Bank queue'),
                'cta_url' => BankAccountsResource::getUrl('index', ['tab' => 'imports']),
            ]
            : [
                'tone' => 'success',
                'title' => $statement->filename,
                'subtitle' => __(':bank · :date', [
                    'bank' => $statement->bank_name ?: __('Bank statement'),
                    'date' => $statement->statement_date !== null
                        ? Carbon::parse((string) $statement->statement_date)->format('M j, Y')
                        : '—',
                ]),
            ],
        };

        return [
            'statement' => [
                'id' => $statement->id,
                'filename' => $statement->filename,
                'status' => $statement->status,
            ],
            'currency' => InsightFormatter::currency(),
            'total_rows' => (int) $statement->total_rows,
            'imported_rows' => (int) $statement->imported_rows,
            'duplicate_rows' => (int) $statement->duplicate_rows,
            'total_lines' => $totalLines,
            'pending_post' => $pendingPost,
            'post_rate' => $postRate,
            'credit_total' => $creditTotal,
            'debit_total' => $debitTotal,
            'unassigned' => $unassigned,
            'sparkline' => $sparkline,
            'sparkline_max' => max(1, max($sparkline)),
            'recent' => $recent,
            'hero' => $hero,
            'kpis' => [
                [
                    'key' => 'lines',
                    'label' => __('Lines'),
                    'value' => (string) $totalLines,
                    'sub' => __('In file'),
                    'icon' => 'heroicon-o-queue-list',
                    'accent' => 'indigo',
                ],
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
                    'value' => (string) ($posted + $mirrored),
                    'sub' => $postRate.'%',
                    'icon' => 'heroicon-o-check-circle',
                    'accent' => 'emerald',
                ],
                [
                    'key' => 'credits',
                    'label' => __('Credits'),
                    'value' => InsightFormatter::compactAmount($creditTotal),
                    'sub' => InsightFormatter::money($creditTotal),
                    'icon' => 'heroicon-o-arrow-trending-up',
                    'accent' => 'teal',
                ],
                [
                    'key' => 'debits',
                    'label' => __('Debits'),
                    'value' => InsightFormatter::compactAmount($debitTotal),
                    'sub' => InsightFormatter::money($debitTotal),
                    'icon' => 'heroicon-o-arrow-trending-down',
                    'accent' => 'rose',
                ],
                [
                    'key' => 'unassigned',
                    'label' => __('Unassigned'),
                    'value' => (string) $unassigned,
                    'sub' => __('Credit lines'),
                    'icon' => 'heroicon-o-user-plus',
                    'accent' => $unassigned > 0 ? 'amber' : 'sky',
                ],
            ],
            'status_breakdown' => [
                ['label' => __('Imported'), 'count' => $imported, 'accent' => 'amber'],
                ['label' => __('Mirrored'), 'count' => $mirrored, 'accent' => 'sky'],
                ['label' => __('Posted'), 'count' => $posted, 'accent' => 'emerald'],
                ['label' => __('Duplicate'), 'count' => $duplicate, 'accent' => 'violet'],
                ['label' => __('Ignored'), 'count' => $ignored, 'accent' => 'rose'],
            ],
        ];
    }
}
