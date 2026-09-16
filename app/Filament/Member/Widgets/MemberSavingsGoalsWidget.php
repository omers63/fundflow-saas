<?php

declare(strict_types=1);

namespace App\Filament\Member\Widgets;

use App\Models\Tenant\MemberSavingsGoal;
use App\Services\Savings\MemberLoanReadinessService;
use App\Services\Savings\MemberSavingsGoalService;
use App\Support\Tenant\CurrentMember;
use Filament\Widgets\Widget;

class MemberSavingsGoalsWidget extends Widget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected string $view = 'filament.member.widgets.member-savings-goals';

    protected int|string|array $columnSpan = 'full';

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $member = CurrentMember::get();
        if ($member === null) {
            return ['goals' => [], 'loanReadiness' => null];
        }

        $service = app(MemberSavingsGoalService::class);

        $goals = MemberSavingsGoal::query()
            ->where('member_id', $member->id)
            ->where('status', MemberSavingsGoal::STATUS_ACTIVE)
            ->latest('id')
            ->limit(3)
            ->get()
            ->map(function (MemberSavingsGoal $goal) use ($service): array {
                return [
                    'goal' => $goal,
                    'progress' => $service->progress($goal),
                ];
            })
            ->all();

        return [
            'goals' => $goals,
            'loanReadiness' => app(MemberLoanReadinessService::class)->forMember($member),
        ];
    }
}
