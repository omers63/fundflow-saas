<?php

declare(strict_types=1);

namespace App\Services;

use App\Filament\Tenant\Resources\MemberRequests\MemberRequestResource;
use App\Filament\Tenant\Resources\Members\MemberResource;
use App\Models\Tenant\MemberRequest;
use App\Support\BusinessDay;
use Carbon\Carbon;

final class MemberRequestInsightsService
{
    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $now = BusinessDay::now();

        $pending = MemberRequest::query()->where('status', MemberRequest::STATUS_PENDING)->count();
        $approved = MemberRequest::query()->where('status', MemberRequest::STATUS_APPROVED)->count();
        $rejected = MemberRequest::query()->where('status', MemberRequest::STATUS_REJECTED)->count();

        $newThisMonth = MemberRequest::query()
            ->whereMonth('created_at', $now->month)
            ->whereYear('created_at', $now->year)
            ->count();

        $pendingOverSla = MemberRequest::query()
            ->where('status', MemberRequest::STATUS_PENDING)
            ->where('created_at', '<', $now->copy()->subDays(3))
            ->count();

        $oldestPending = MemberRequest::query()
            ->where('status', MemberRequest::STATUS_PENDING)
            ->with('requester')
            ->orderBy('created_at')
            ->limit(6)
            ->get()
            ->map(fn (MemberRequest $request): array => [
                'id' => $request->id,
                'name' => $request->requester?->name ?? __('Unknown'),
                'type' => MemberRequest::typeLabel($request->type),
                'days_waiting' => (int) Carbon::parse($request->created_at)->diffInDays($now),
                'view_url' => MemberRequestResource::getUrl('view', ['record' => $request]),
            ])
            ->all();

        $typeCounts = MemberRequest::query()
            ->selectRaw('type, COUNT(*) as total')
            ->groupBy('type')
            ->pluck('total', 'type');

        $typeBreakdown = collect(MemberRequest::typeOptions())
            ->map(fn (string $label, string $type): array => [
                'type' => $type,
                'label' => $label,
                'count' => (int) ($typeCounts[$type] ?? 0),
            ])
            ->values()
            ->all();

        return [
            'pending' => $pending,
            'approved' => $approved,
            'rejected' => $rejected,
            'new_this_month' => $newThisMonth,
            'pending_over_sla' => $pendingOverSla,
            'oldest_pending' => $oldestPending,
            'type_breakdown' => $typeBreakdown,
            'pipeline' => [
                'pending_url' => MemberRequestResource::listTabUrl('pending'),
                'approved_url' => MemberRequestResource::listTabUrl('approved'),
                'members_url' => MemberResource::getUrl('index'),
            ],
        ];
    }
}
