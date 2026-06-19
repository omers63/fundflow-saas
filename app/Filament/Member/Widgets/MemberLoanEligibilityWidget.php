<?php

declare(strict_types=1);

namespace App\Filament\Member\Widgets;

use App\Filament\Support\RequestLoanEligibilityOverrideAction;
use App\Models\Tenant\Loan;
use App\Models\Tenant\Member;
use App\Services\Loans\LoanEligibilityOverrideRequestService;
use App\Services\LoanService;
use App\Support\Tenant\CurrentMember;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Widgets\Widget;

class MemberLoanEligibilityWidget extends Widget implements HasActions
{
    use InteractsWithActions;

    protected static bool $isDiscovered = false;

    protected static ?int $sort = -15;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.member.widgets.member-loan-eligibility';

    public static function canView(): bool
    {
        $member = CurrentMember::get();

        if (! $member instanceof Member) {
            return false;
        }

        if (Loan::query()->where('member_id', $member->id)->where('status', 'active')->exists()) {
            return false;
        }

        $eligibility = app(LoanService::class)->checkEligibility($member);

        if ($eligibility['eligible']) {
            return false;
        }

        $overrides = app(LoanEligibilityOverrideRequestService::class);

        return $overrides->canSubmit($member)
            || $overrides->pendingRequestFor($member) !== null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $member = CurrentMember::get();
        $eligibility = $member instanceof Member
            ? app(LoanService::class)->checkEligibility($member)
            : ['eligible' => false, 'reasons' => []];

        $overrides = app(LoanEligibilityOverrideRequestService::class);

        return [
            'reason' => $eligibility['reasons'][0] ?? null,
            'hasPending' => $member instanceof Member && $overrides->pendingRequestFor($member) !== null,
            'canRequest' => $member instanceof Member && $overrides->canSubmit($member),
        ];
    }

    public function requestEligibilityOverrideAction(): Action
    {
        return RequestLoanEligibilityOverrideAction::make();
    }

    public function eligibilityReviewPendingAction(): Action
    {
        return RequestLoanEligibilityOverrideAction::pendingReviewAction();
    }
}
