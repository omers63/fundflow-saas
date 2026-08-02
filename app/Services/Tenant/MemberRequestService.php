<?php

declare(strict_types=1);

namespace App\Services\Tenant;

use App\Filament\Member\Pages\RequestsPage;
use App\Filament\Member\Resources\MyContributions\MyContributionResource;
use App\Filament\Member\Resources\MyDependents\MyDependentResource;
use App\Filament\Support\MemberDatabaseNotification;
use App\Filament\Support\MemberFilamentActions;
use App\Models\Tenant\Member;
use App\Models\Tenant\MemberRequest;
use App\Models\Tenant\User;
use App\Notifications\Tenant\NewMemberRequestNotification;
use App\Services\DependentAllocationService;
use App\Services\MemberFreezeService;
use App\Services\MemberStatusService;
use App\Services\MemberWithdrawalSettlementService;
use App\Services\OpenCycleContributionOverrideService;
use App\Services\OperationalReviewWorkflowService;
use App\Support\BusinessDay;
use App\Support\MemberUserEmail;
use App\Support\TenantAbsoluteUrl;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MemberRequestService
{
    public function __construct(
        private readonly HouseholdMemberService $householdMembers,
        private readonly DependentAllocationService $allocations,
        private readonly MemberStatusService $statuses,
        private readonly MemberFreezeService $freezes,
        private readonly OpenCycleContributionOverrideService $openCycleOverrides,
        private readonly OperationalReviewWorkflowService $reviewWorkflow,
    ) {}

    public function submit(Member $requester, string $type, array $payload, bool $notifyAdmins = true): MemberRequest
    {
        if ($type === MemberRequest::TYPE_VOLUNTARY_CONTRIBUTION) {
            throw ValidationException::withMessages([
                'type' => __('Contribution top-up requests are no longer accepted. Choose a higher standing monthly contribution amount instead.'),
            ]);
        }

        if ($type === MemberRequest::TYPE_OPEN_CYCLE_CONTRIBUTION) {
            $payload = $this->openCycleOverrides->normalizePayload($requester, $payload);
        } else {
            $this->validatePayload($requester, $type, $payload);
            $this->assertNoPendingDuplicate($requester, $type);
        }

        return DB::transaction(function () use ($requester, $type, $payload, $notifyAdmins): MemberRequest {
            if ($type === MemberRequest::TYPE_ADD_DEPENDENT && $requester->isSponsoredDependent()) {
                $newEmail = strtolower(trim((string) ($payload['new_email'] ?? '')));

                $this->householdMembers->establishAsHouseholdParent($requester, $newEmail);
                $requester = $requester->fresh() ?? $requester;
            }

            $request = MemberRequest::query()->create([
                'requester_member_id' => $requester->id,
                'type' => $type,
                'status' => MemberRequest::STATUS_PENDING,
                'payload' => $payload,
            ]);

            if ($type === MemberRequest::TYPE_FREEZE_MEMBERSHIP) {
                $this->freezes->notifyFreezeRequested($requester, $payload);
            }

            if ($notifyAdmins) {
                $this->reviewWorkflow->notifyAdmins(new NewMemberRequestNotification($request, $requester));
            }

            return $request;
        });
    }

    /**
     * @throws ValidationException
     */
    protected function validatePayload(Member $requester, string $type, array $payload): void
    {
        match ($type) {
            MemberRequest::TYPE_ADD_DEPENDENT => $this->validateAddDependent($requester, $payload),
            MemberRequest::TYPE_REMOVE_DEPENDENT => $this->validateRemoveDependent($requester, $payload),
            MemberRequest::TYPE_OWN_ALLOCATION => $this->validateOwnAllocation($requester, $payload),
            MemberRequest::TYPE_DEPENDENT_ALLOCATION => $this->validateDependentAllocation($requester, $payload),
            MemberRequest::TYPE_REQUEST_INDEPENDENCE => $this->validateIndependence($requester),
            MemberRequest::TYPE_FREEZE_MEMBERSHIP => $this->validateFreezeMembership($requester, $payload),
            MemberRequest::TYPE_UNFREEZE_MEMBERSHIP => $this->validateUnfreezeMembership($requester),
            MemberRequest::TYPE_EXTEND_FREEZE_MEMBERSHIP => $this->validateExtendFreezeMembership($requester, $payload),
            MemberRequest::TYPE_WITHDRAW_MEMBERSHIP => $this->validateWithdrawMembership($requester, $payload),
            MemberRequest::TYPE_REINSTATE_MEMBERSHIP => $this->validateReinstateMembership($requester),
            MemberRequest::TYPE_RELEASE_PAYOUT => $this->validateReleasePayout($requester),
            MemberRequest::TYPE_OPEN_CYCLE_CONTRIBUTION => null,
            default => throw ValidationException::withMessages(['type' => __('Invalid request type.')]),
        };
    }

    protected function validateAddDependent(Member $requester, array $payload): void
    {
        if (blank($payload['details'] ?? null)) {
            throw ValidationException::withMessages([
                'details' => __('Please describe who you want to add as a dependent.'),
            ]);
        }

        if (! $requester->isSponsoredDependent()) {
            return;
        }

        app(MemberUserEmail::class)->validateNewLoginEmail(
            (string) ($payload['new_email'] ?? ''),
            $requester->user_id,
        );
    }

    protected function validateRemoveDependent(Member $requester, array $payload): void
    {
        $id = (int) ($payload['dependent_member_id'] ?? 0);

        if ($id <= 0) {
            throw ValidationException::withMessages([
                'dependent_member_id' => __('Select a dependent.'),
            ]);
        }

        $dependent = Member::query()->find($id);

        if (! $dependent instanceof Member || (int) $dependent->parent_member_id !== (int) $requester->id) {
            throw ValidationException::withMessages([
                'dependent_member_id' => __('Invalid dependent.'),
            ]);
        }

        app(MemberUserEmail::class)->validateNewLoginEmail(
            (string) ($payload['separated_email'] ?? ''),
            $dependent->user_id,
            'separated_email',
        );
    }

    protected function validateOwnAllocation(Member $requester, array $payload): void
    {
        if ($requester->parent_member_id !== null) {
            throw ValidationException::withMessages([
                'member' => __('You must become independent before changing your own allocation. Submit an independence request first.'),
            ]);
        }

        $amount = (int) ($payload['requested_amount'] ?? 0);

        if (! Member::isValidContributionAmount($amount)) {
            throw ValidationException::withMessages([
                'requested_amount' => __('Choose a valid monthly amount.'),
            ]);
        }
    }

    protected function validateDependentAllocation(Member $requester, array $payload): void
    {
        $dependentId = (int) ($payload['dependent_member_id'] ?? 0);
        $amount = (int) ($payload['requested_amount'] ?? 0);

        if ($dependentId <= 0) {
            throw ValidationException::withMessages([
                'dependent_member_id' => __('Select a dependent.'),
            ]);
        }

        if (! Member::isValidDependentContributionAmount($amount)) {
            throw ValidationException::withMessages([
                'requested_amount' => __('Choose a valid monthly amount.'),
            ]);
        }

        $dependent = Member::query()->find($dependentId);

        if (! $dependent instanceof Member || (int) $dependent->parent_member_id !== (int) $requester->id) {
            throw ValidationException::withMessages([
                'dependent_member_id' => __('Invalid dependent.'),
            ]);
        }
    }

    protected function validateIndependence(Member $requester): void
    {
        if ($requester->parent_member_id === null) {
            throw ValidationException::withMessages([
                'member' => __('You are not linked to a household parent.'),
            ]);
        }
    }

    protected function validateFreezeMembership(Member $requester, array $payload): void
    {
        if ($requester->status !== 'active') {
            throw ValidationException::withMessages([
                'member' => __('Only active members can request a membership freeze.'),
            ]);
        }

        $this->freezes->validatePlan($requester, [
            'cycles' => (int) ($payload['cycles'] ?? 0),
            'household_mode' => (string) ($payload['household_mode'] ?? MemberFreezeService::HOUSEHOLD_SELF_ONLY),
            'temporary_parent_member_id' => $payload['temporary_parent_member_id'] ?? null,
            'reason' => (string) ($payload['reason'] ?? ''),
        ]);

        $this->freezes->assertCanSubmitOrApprove($requester, forApprove: false);
    }

    protected function validateUnfreezeMembership(Member $requester): void
    {
        if ($requester->status !== 'inactive' || $requester->frozen_at === null) {
            throw ValidationException::withMessages([
                'member' => __('Only frozen members can request to unfreeze membership.'),
            ]);
        }
    }

    protected function validateExtendFreezeMembership(Member $requester, array $payload): void
    {
        if (! $this->freezes->isFrozen($requester)) {
            throw ValidationException::withMessages([
                'member' => __('Only frozen members can extend a freeze plan.'),
            ]);
        }

        $cycles = (int) ($payload['cycles'] ?? 0);

        if ($cycles < MemberFreezeService::MIN_CYCLES || $cycles > MemberFreezeService::MAX_CYCLES) {
            throw ValidationException::withMessages([
                'cycles' => __('Extension must be between :min and :max cycles.', [
                    'min' => MemberFreezeService::MIN_CYCLES,
                    'max' => MemberFreezeService::MAX_CYCLES,
                ]),
            ]);
        }
    }

    protected function validateReinstateMembership(Member $requester): void
    {
        if ($requester->status !== 'withdrawn') {
            throw ValidationException::withMessages([
                'member' => __('Only withdrawn members can request reinstatement.'),
            ]);
        }
    }

    protected function validateReleasePayout(Member $requester): void
    {
        if ($requester->status !== 'withdrawn') {
            throw ValidationException::withMessages([
                'member' => __('Payout release applies only to withdrawn members.'),
            ]);
        }

        if ($requester->payout_frozen_at === null) {
            throw ValidationException::withMessages([
                'member' => __('Payout is not frozen for this member.'),
            ]);
        }
    }

    protected function validateWithdrawMembership(Member $requester, array $payload): void
    {
        if (in_array($requester->status, ['withdrawn'], true)) {
            throw ValidationException::withMessages([
                'member' => __('Your membership has already ended.'),
            ]);
        }

        if ($requester->frozen_at !== null) {
            throw ValidationException::withMessages([
                'member' => __('Unfreeze membership before requesting to leave the fund.'),
            ]);
        }

        $plan = [
            'reason' => (string) ($payload['reason'] ?? ''),
            'household_mode' => (string) ($payload['household_mode'] ?? MemberWithdrawalSettlementService::HOUSEHOLD_SELF_ONLY),
            'permanent_parent_member_id' => $payload['permanent_parent_member_id'] ?? null,
        ];

        $settlement = app(MemberWithdrawalSettlementService::class);
        $settlement->validatePlan($requester, $plan);
        $settlement->assertCanSubmitOrApprove($requester, forApprove: false);
    }

    protected function assertNoPendingDuplicate(Member $requester, string $type): void
    {
        $exists = MemberRequest::query()
            ->where('requester_member_id', $requester->id)
            ->where('type', $type)
            ->where('status', MemberRequest::STATUS_PENDING)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'type' => __('You already have a pending request of this type.'),
            ]);
        }
    }

    public function approve(MemberRequest $request, User $admin, array $options = []): void
    {
        if (! $request->isPending()) {
            throw ValidationException::withMessages([
                'status' => __('This request is no longer pending.'),
            ]);
        }

        $requester = $request->requester()->with('user')->firstOrFail();
        $payload = $request->payload ?? [];

        DB::transaction(function () use ($request, $requester, $payload, $admin, $options): void {
            match ($request->type) {
                MemberRequest::TYPE_ADD_DEPENDENT => null,
                MemberRequest::TYPE_REMOVE_DEPENDENT => $this->applyRemoveDependent($requester, $payload),
                MemberRequest::TYPE_OWN_ALLOCATION => $this->applyOwnAllocation($requester, $payload),
                MemberRequest::TYPE_DEPENDENT_ALLOCATION => $this->applyDependentAllocation($requester, $payload, $admin),
                MemberRequest::TYPE_REQUEST_INDEPENDENCE => $this->applyIndependence($requester),
                MemberRequest::TYPE_FREEZE_MEMBERSHIP => $this->applyFreezeMembership($requester, $payload, $options),
                MemberRequest::TYPE_UNFREEZE_MEMBERSHIP => $this->applyUnfreezeMembership($requester),
                MemberRequest::TYPE_EXTEND_FREEZE_MEMBERSHIP => $this->applyExtendFreezeMembership($requester, $payload),
                MemberRequest::TYPE_WITHDRAW_MEMBERSHIP => $this->applyWithdrawMembership($requester, $payload, $options),
                MemberRequest::TYPE_REINSTATE_MEMBERSHIP => $this->applyReinstateMembership($requester, $payload),
                MemberRequest::TYPE_RELEASE_PAYOUT => $this->applyReleasePayout($requester, $payload),
                MemberRequest::TYPE_OPEN_CYCLE_CONTRIBUTION,
                MemberRequest::TYPE_VOLUNTARY_CONTRIBUTION => $this->openCycleOverrides->applyApproved($requester, $payload),
                default => throw ValidationException::withMessages(['type' => __('Unknown request type.')]),
            };

            $request->update([
                'status' => MemberRequest::STATUS_APPROVED,
                'reviewed_by_user_id' => $admin->id,
                'reviewed_at' => BusinessDay::now(),
            ]);
        });

        $this->notifyRequester($requester, $request, 'approved');
    }

    public function reject(MemberRequest $request, User $admin, ?string $note = null): void
    {
        if (! $request->isPending()) {
            throw ValidationException::withMessages([
                'status' => __('This request is no longer pending.'),
            ]);
        }

        $requester = $request->requester()->with('user')->firstOrFail();

        $request->update([
            'status' => MemberRequest::STATUS_REJECTED,
            'admin_note' => $note,
            'reviewed_by_user_id' => $admin->id,
            'reviewed_at' => BusinessDay::now(),
        ]);

        $this->notifyRequester($requester, $request, 'rejected');
    }

    protected function applyRemoveDependent(Member $parent, array $payload): void
    {
        $id = (int) ($payload['dependent_member_id'] ?? 0);
        $dependent = Member::query()->findOrFail($id);

        if ((int) $dependent->parent_member_id !== (int) $parent->id) {
            throw ValidationException::withMessages([
                'dependent' => __('Dependent is no longer linked to this parent.'),
            ]);
        }

        $separatedEmail = strtolower(trim((string) ($payload['separated_email'] ?? '')));

        if ($separatedEmail === '') {
            throw ValidationException::withMessages([
                'separated_email' => __('Enter a unique email for the dependent\'s login.'),
            ]);
        }

        $this->householdMembers->establishAsHouseholdParent($dependent, $separatedEmail);
    }

    protected function applyOwnAllocation(Member $member, array $payload): void
    {
        $amount = (int) ($payload['requested_amount'] ?? 0);

        if (! Member::isValidContributionAmount($amount)) {
            throw ValidationException::withMessages([
                'requested_amount' => __('Invalid amount.'),
            ]);
        }

        if ((int) $member->monthly_contribution_amount === $amount) {
            throw ValidationException::withMessages([
                'requested_amount' => __('Amount matches current allocation; nothing to apply.'),
            ]);
        }

        $member->update(['monthly_contribution_amount' => $amount]);
    }

    protected function applyDependentAllocation(Member $parent, array $payload, User $admin): void
    {
        $dependentId = (int) ($payload['dependent_member_id'] ?? 0);
        $amount = (int) ($payload['requested_amount'] ?? 0);
        $dependent = Member::query()->findOrFail($dependentId);

        if ((int) $dependent->parent_member_id !== (int) $parent->id) {
            throw ValidationException::withMessages([
                'dependent' => __('Invalid dependent.'),
            ]);
        }

        if ((int) $dependent->monthly_contribution_amount === $amount) {
            throw ValidationException::withMessages([
                'requested_amount' => __('Amount matches current allocation; nothing to apply.'),
            ]);
        }

        $note = isset($payload['note']) ? (string) $payload['note'] : null;
        $change = $this->allocations->changeAllocation(
            parent: $parent,
            dependent: $dependent,
            newAmount: $amount,
            note: $note,
            changedBy: $admin,
        );

        if ($change === null) {
            throw ValidationException::withMessages([
                'requested_amount' => __('Allocation could not be applied.'),
            ]);
        }
    }

    protected function applyIndependence(Member $member): void
    {
        if ($member->parent_member_id === null) {
            return;
        }

        $this->householdMembers->removeFromHousehold($member);
    }

    protected function applyFreezeMembership(Member $member, array $payload, array $options = []): void
    {
        $freezeDate = isset($options['freeze_date'])
            ? MemberFilamentActions::resolveFreezeDate($options['freeze_date'])
            : null;

        try {
            $this->freezes->applyFreeze($member, [
                'cycles' => (int) ($payload['cycles'] ?? 0),
                'household_mode' => (string) ($payload['household_mode'] ?? MemberFreezeService::HOUSEHOLD_SELF_ONLY),
                'temporary_parent_member_id' => $payload['temporary_parent_member_id'] ?? null,
                'reason' => (string) ($payload['reason'] ?? ''),
            ], $freezeDate, null);
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'member' => $exception->getMessage(),
            ]);
        }
    }

    protected function applyUnfreezeMembership(Member $member): void
    {
        try {
            $this->freezes->applyUnfreeze($member);
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'member' => $exception->getMessage(),
            ]);
        }
    }

    protected function applyExtendFreezeMembership(Member $member, array $payload): void
    {
        try {
            $this->freezes->extendFreeze(
                $member,
                (int) ($payload['cycles'] ?? 0),
                (string) ($payload['reason'] ?? ''),
            );
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'member' => $exception->getMessage(),
            ]);
        }
    }

    protected function applyWithdrawMembership(Member $member, array $payload, array $options = []): void
    {
        $withdrawDate = isset($options['withdraw_date'])
            ? MemberFilamentActions::resolveWithdrawDate($options['withdraw_date'])
            : null;

        try {
            $this->statuses->withdraw(
                $member,
                (string) ($payload['reason'] ?? ''),
                holdPayout: false,
                withdrawDate: $withdrawDate,
                plan: [
                    'reason' => (string) ($payload['reason'] ?? ''),
                    'household_mode' => (string) ($payload['household_mode'] ?? MemberWithdrawalSettlementService::HOUSEHOLD_SELF_ONLY),
                    'permanent_parent_member_id' => $payload['permanent_parent_member_id'] ?? null,
                ],
            );
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'member' => $exception->getMessage(),
            ]);
        }
    }

    protected function applyReinstateMembership(Member $member, array $payload): void
    {
        try {
            $this->statuses->reinstate($member, (string) ($payload['reason'] ?? ''));
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'member' => $exception->getMessage(),
            ]);
        }
    }

    protected function applyReleasePayout(Member $member, array $payload): void
    {
        try {
            $this->statuses->releasePayoutReview($member, (string) ($payload['reason'] ?? ''));
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'member' => $exception->getMessage(),
            ]);
        }
    }

    protected function notifyRequester(Member $requester, MemberRequest $request, string $outcome): void
    {
        $user = $requester->user;

        if ($user === null) {
            return;
        }

        $body = MemberRequest::typeLabel($request->type);

        if ($request->admin_note && $outcome === 'rejected') {
            $body .= ': '.$request->admin_note;
        }

        MemberDatabaseNotification::send($user, function (Notification $notification) use ($outcome, $body, $request): void {
            $notification
                ->title($outcome === 'approved' ? __('Request approved') : __('Request declined'))
                ->body($body)
                ->icon($outcome === 'approved' ? 'heroicon-o-check-circle' : 'heroicon-o-x-circle')
                ->iconColor($outcome === 'approved' ? 'success' : 'danger')
                ->actions([
                    Action::make('view')
                        ->label(__('View request'))
                        ->url(TenantAbsoluteUrl::resolve($this->memberRequestUrlFor($request)))
                        ->markAsRead(),
                ]);
        });
    }

    protected function memberRequestUrlFor(MemberRequest $request): string
    {
        if (in_array($request->type, [
            MemberRequest::TYPE_ADD_DEPENDENT,
            MemberRequest::TYPE_REMOVE_DEPENDENT,
        ], true)) {
            return MyDependentResource::getUrl('index', panel: 'member');
        }

        if (
            in_array($request->type, [
                MemberRequest::TYPE_OPEN_CYCLE_CONTRIBUTION,
                MemberRequest::TYPE_VOLUNTARY_CONTRIBUTION,
            ], true)
        ) {
            return MyContributionResource::getUrl('index', panel: 'member');
        }

        return RequestsPage::getUrl([
            'section' => 'membership',
        ], panel: 'member');
    }
}
