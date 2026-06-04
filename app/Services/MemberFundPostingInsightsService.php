<?php

declare(strict_types=1);

namespace App\Services;

use App\Filament\Member\Resources\MyFundPostings\MyFundPostingResource;
use App\Models\Tenant\FundPosting;
use App\Models\Tenant\Member;
use App\Support\BusinessDay;
use App\Support\Insights\InsightFormatter;
use App\Support\Tenant\CurrentMember;
use Carbon\Carbon;

final class MemberFundPostingInsightsService
{
    /**
     * @return array<string, mixed>
     */
    public function snapshot(?Member $member = null): array
    {
        $member = $member ?? CurrentMember::get();

        if ($member === null) {
            return [];
        }

        $base = FundPosting::query()->where('member_id', $member->id);

        $pending = (clone $base)->where('status', 'pending')->count();
        $accepted = (clone $base)->where('status', 'accepted')->count();
        $rejected = (clone $base)->where('status', 'rejected')->count();
        $total = $pending + $accepted + $rejected;

        $pendingAmount = (float) (clone $base)->where('status', 'pending')->sum('amount');
        $acceptedAmount = (float) (clone $base)->where('status', 'accepted')->sum('amount');

        $recent = (clone $base)
            ->latest()
            ->limit(5)
            ->get()
            ->map(fn (FundPosting $posting): array => [
                'id' => $posting->id,
                'amount' => InsightFormatter::money((float) $posting->amount),
                'status' => $posting->status,
                'status_label' => match ($posting->status) {
                    'pending' => __('Pending review'),
                    'accepted' => __('Accepted'),
                    'rejected' => __('Rejected'),
                    default => ucfirst($posting->status),
                },
                'date' => $posting->posting_date !== null
                    ? Carbon::parse((string) $posting->posting_date)->format('d M Y')
                    : '—',
            ])
            ->all();

        return [
            'total' => $total,
            'pending' => $pending,
            'accepted' => $accepted,
            'rejected' => $rejected,
            'pending_amount' => InsightFormatter::money($pendingAmount),
            'accepted_amount' => InsightFormatter::money($acceptedAmount),
            'deposits_url' => MyFundPostingResource::getUrl('index'),
            'create_url' => MyFundPostingResource::getUrl('create'),
            'recent' => $recent,
            'sparkline' => $this->monthlySparkline($member),
        ];
    }

    /**
     * @return list<int>
     */
    private function monthlySparkline(Member $member): array
    {
        $now = BusinessDay::now();
        $oldestMonth = $now->copy()->subMonths(5)->startOfMonth();
        $monthCounts = [];

        FundPosting::query()
            ->where('member_id', $member->id)
            ->whereBetween('created_at', [$oldestMonth, $now->copy()->endOfMonth()])
            ->get(['created_at'])
            ->each(function (FundPosting $posting) use (&$monthCounts): void {
                $createdAt = $posting->created_at;

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
