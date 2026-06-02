<?php

declare(strict_types=1);

namespace App\Services;

use App\Filament\Member\Resources\MyAccounts\MyAccountResource;
use App\Filament\Member\Resources\MyCashOutRequests\MyCashOutRequestResource;
use App\Models\Tenant\CashOutRequest;
use App\Models\Tenant\Member;
use App\Support\Insights\InsightFormatter;
use App\Support\Tenant\CurrentMember;
use Carbon\Carbon;

final class MemberCashOutInsightsService
{
    public function __construct(
        protected MemberCashOutService $cashOuts,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function snapshot(?Member $member = null): array
    {
        $member = $member ?? CurrentMember::get();

        if ($member === null) {
            return [];
        }

        $base = CashOutRequest::query()->where('member_id', $member->id);

        $pending = (int) (clone $base)->where('status', 'pending')->count();
        $accepted = (int) (clone $base)->where('status', 'accepted')->count();
        $rejected = (int) (clone $base)->where('status', 'rejected')->count();
        $total = $pending + $accepted + $rejected;

        $pendingAmount = (float) (clone $base)->where('status', 'pending')->sum('amount');
        $acceptedAmount = (float) (clone $base)->where('status', 'accepted')->sum('amount');

        $cashBalance = max(0.0, $member->getCashBalance());
        $emiReserved = $this->cashOuts->reservedForNextEmi($member);
        $available = $this->cashOuts->availableCashForWithdrawal($member);

        $sparkline = $this->monthlySparkline($member);
        $sparklineMax = max(1, max($sparkline));

        return [
            'hero' => $this->buildHero($pending, $pendingAmount, $available),
            'kpis' => $this->buildKpis(
                $total,
                $pending,
                $accepted,
                $rejected,
                $available,
                $acceptedAmount,
                $cashBalance,
                $emiReserved,
            ),
            'sparkline' => $sparkline,
            'sparkline_max' => $sparklineMax,
            'availability' => [
                'cash_balance' => InsightFormatter::money($cashBalance),
                'emi_reserved' => InsightFormatter::money($emiReserved),
                'pending_withdrawals' => InsightFormatter::money($pendingAmount),
                'available' => InsightFormatter::money($available),
            ],
            'recent' => $this->recentRequests($member),
            'index_url' => MyCashOutRequestResource::getUrl('index'),
            'create_url' => MyCashOutRequestResource::getUrl('create'),
        ];
    }

    /**
     * @return array{tone: string, title: string, subtitle: string, cta_label?: string, cta_url?: string}
     */
    private function buildHero(int $pending, float $pendingAmount, float $available): array
    {
        if ($pending > 0) {
            return [
                'tone' => 'amber',
                'title' => __('Withdrawals awaiting review'),
                'subtitle' => trans_choice(
                    ':count request pending review totaling :amount|:count requests pending review totaling :amount',
                    $pending,
                    [
                        'count' => $pending,
                        'amount' => InsightFormatter::money($pendingAmount),
                    ],
                ),
            ];
        }

        if ($available > 0.00001) {
            return [
                'tone' => 'success',
                'title' => __('Cash available to withdraw'),
                'subtitle' => __(':amount can be requested now (after EMI reserve and pending requests).', [
                    'amount' => InsightFormatter::money($available),
                ]),
                'cta_label' => __('Request cash out'),
                'cta_url' => MyCashOutRequestResource::getUrl('create'),
            ];
        }

        return [
            'tone' => 'warning',
            'title' => __('No cash available to withdraw'),
            'subtitle' => __('Your balance may be reserved for an upcoming EMI or tied up in pending withdrawal requests.'),
            'cta_label' => __('View cash account'),
            'cta_url' => MyAccountResource::getUrl('index'),
        ];
    }

    /**
     * @return list<array{label: string, value: string, sub: string, accent: string, icon: string, url?: string|null, value_class?: string|null}>
     */
    private function buildKpis(
        int $total,
        int $pending,
        int $accepted,
        int $rejected,
        float $available,
        float $acceptedAmount,
        float $cashBalance,
        float $emiReserved,
    ): array {
        $indexUrl = MyCashOutRequestResource::getUrl('index');

        return [
            [
                'label' => __('Total'),
                'value' => (string) $total,
                'sub' => __('All requests'),
                'accent' => 'sky',
                'icon' => 'heroicon-o-queue-list',
                'url' => $indexUrl,
            ],
            [
                'label' => __('Pending'),
                'value' => (string) $pending,
                'sub' => __('Awaiting admin'),
                'accent' => 'amber',
                'icon' => 'heroicon-o-clock',
                'url' => $indexUrl,
            ],
            [
                'label' => __('Accepted'),
                'value' => (string) $accepted,
                'sub' => InsightFormatter::money($acceptedAmount),
                'accent' => 'emerald',
                'icon' => 'heroicon-o-check-circle',
                'url' => $indexUrl,
            ],
            [
                'label' => __('Rejected'),
                'value' => (string) $rejected,
                'sub' => __('Not processed'),
                'accent' => 'rose',
                'icon' => 'heroicon-o-x-circle',
                'url' => $indexUrl,
            ],
            [
                'label' => __('Available'),
                'value' => InsightFormatter::money($available),
                'sub' => __('Can withdraw now'),
                'accent' => 'teal',
                'icon' => 'heroicon-o-banknotes',
                'url' => MyCashOutRequestResource::getUrl('create'),
                'value_class' => $available > 0.00001
                    ? 'text-teal-700 dark:text-teal-300'
                    : 'text-gray-900 dark:text-white',
            ],
            [
                'label' => __('Cash balance'),
                'value' => InsightFormatter::money($cashBalance),
                'sub' => $emiReserved > 0.00001
                    ? __(':amount EMI reserved', ['amount' => InsightFormatter::money($emiReserved)])
                    : __('No EMI reserve'),
                'accent' => 'indigo',
                'icon' => 'heroicon-o-wallet',
                'url' => MyAccountResource::getUrl('index'),
            ],
        ];
    }

    /**
     * @return list<array{id: int, amount: string, status: string, status_label: string, date: string}>
     */
    private function recentRequests(Member $member): array
    {
        return CashOutRequest::query()
            ->where('member_id', $member->id)
            ->latest()
            ->limit(5)
            ->get()
            ->map(fn (CashOutRequest $request): array => [
                'id' => $request->id,
                'amount' => InsightFormatter::money((float) $request->amount),
                'status' => $request->status,
                'status_label' => match ($request->status) {
                    'pending' => __('Pending review'),
                    'accepted' => __('Accepted'),
                    'rejected' => __('Rejected'),
                    default => ucfirst($request->status),
                },
                'date' => $request->created_at?->format('d M Y') ?? '—',
            ])
            ->all();
    }

    /**
     * @return list<int>
     */
    private function monthlySparkline(Member $member): array
    {
        $now = Carbon::now();
        $oldestMonth = $now->copy()->subMonths(5)->startOfMonth();
        $monthCounts = [];

        CashOutRequest::query()
            ->where('member_id', $member->id)
            ->whereBetween('created_at', [$oldestMonth, $now->copy()->endOfMonth()])
            ->get(['created_at'])
            ->each(function (CashOutRequest $request) use (&$monthCounts): void {
                $createdAt = $request->created_at;

                if ($createdAt === null) {
                    return;
                }

                $key = Carbon::parse((string) $createdAt)->startOfMonth()->format('Y-m');
                $monthCounts[$key] = ($monthCounts[$key] ?? 0) + 1;
            });

        $points = [];

        for ($i = 5; $i >= 0; $i--) {
            $month = $now->copy()->subMonths($i)->startOfMonth()->format('Y-m');
            $points[] = $monthCounts[$month] ?? 0;
        }

        return $points;
    }
}
