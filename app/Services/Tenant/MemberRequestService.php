<?php

declare(strict_types=1);

namespace App\Services\Tenant;

use App\Filament\Support\MemberDatabaseNotification;
use App\Filament\Support\MemberFilamentActions;
use App\Models\Tenant\Member;
use App\Models\Tenant\MemberRequest;
use App\Models\Tenant\User;
use App\Services\DependentAllocationService;
use App\Services\MemberStatusService;
use App\Services\MemberWithdrawalSettlementService;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MemberRequestService
{
    public function __construct(
        private readonly HouseholdMemberService $householdMembers,
        private readonly DependentAllocationService $allocations,
        private readonly MemberStatusService $statuses,
    ) {}

    public function submit(Member $requester, string $type, array $payload): MemberRequest
    {
        $this->validatePayload($requester, $type, $payload);
        $this->assertNoPendingDuplicate($requester, $type);

        $request = MemberRequest::query()->create([
            'requester_member_id' => $requester->id,
            'type' => $type,
            'status' => MemberRequest::STATUS_PENDING,
            'payload' => $payload,
        ]);

        User::query()
            ->where('is_admin', true)
            ->each(function (User $admin) use ($request, $requester): void {
                Notification::make()
                    ->title(__('New member request'))
                    ->body(
                        ($requester->name ?? __('Member'))
                        .' — '
                        .MemberRequest::typeLabel($request->type)
                    )
                    ->icon('heroicon-o-clipboard-document-list')
                    ->iconColor('warning')
                    ->sendToDatabase($admin);
            });

        return $request;
    }

    /**
     * @throws ValidationException
     */
    protected function validatePayload(Member $requester, string $type, array $payload): void
    {
        match ($type) {
            MemberRequest::TYPE_ADD_DEPENDENT => $this->validateAddDependent($payload),
            MemberRequest::TYPE_REMOVE_DEPENDENT => $this->validateRemoveDependent($requester, $payload),
            MemberRequest::TYPE_OWN_ALLOCATION => $this->validateOwnAllocation($requester, $payload),
            MemberRequest::TYPE_DEPENDENT_ALLOCATION => $this->validateDependentAllocation($requester, $payload),
            MemberRequest::TYPE_REQUEST_INDEPENDENCE => $this->validateIndependence($requester),
            MemberRequest::TYPE_FREEZE_MEMBERSHIP => $this->validateFreezeMembership($requester, $payload),
            MemberRequest::TYPE_UNFREEZE_MEMBERSHIP => $this->validateUnfreezeMembership($requester),
            MemberRequest::TYPE_WITHDRAW_MEMBERSHIP => $this->validateWithdrawMembership($requester, $payload),
            default => throw ValidationException::withMessages(['type' => __('Invalid request type.')]),
        };
    }

    protected function validateAddDependent(array $payload): void
    {
        if (blank($payload['details'] ?? null)) {
            throw ValidationException::withMessages([
                'details' => __('Please describe who you want to add as a dependent.'),
            ]);
        }
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

        if (! Member::isValidContributionAmount($amount)) {
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
    }

    protected function validateUnfreezeMembership(Member $requester): void
    {
        if ($requester->status !== 'inactive') {
            throw ValidationException::withMessages([
                'member' => __('Only inactive members can request to unfreeze membership.'),
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

        try {
            app(MemberWithdrawalSettlementService::class)->assertWithdrawable($requester);
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'member' => $exception->getMessage(),
            ]);
        }
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
                MemberRequest::TYPE_WITHDRAW_MEMBERSHIP => $this->applyWithdrawMembership($requester, $payload, $options),
                default => throw ValidationException::withMessages(['type' => __('Unknown request type.')]),
            };

            $request->update([
                'status' => MemberRequest::STATUS_APPROVED,
                'reviewed_by_user_id' => $admin->id,
                'reviewed_at' => now(),
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
            'reviewed_at' => now(),
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

        $this->householdMembers->removeFromHousehold($dependent);
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

        $this->statuses->freeze(
            $member,
            (string) ($payload['reason'] ?? ''),
            $freezeDate,
        );
    }

    protected function applyUnfreezeMembership(Member $member): void
    {
        $this->statuses->unfreeze($member);
    }

    protected function applyWithdrawMembership(Member $member, array $payload, array $options = []): void
    {
        $withdrawDate = isset($options['withdraw_date'])
            ? MemberFilamentActions::resolveWithdrawDate($options['withdraw_date'])
            : null;

        $this->statuses->withdraw(
            $member,
            (string) ($payload['reason'] ?? ''),
            holdPayout: false,
            withdrawDate: $withdrawDate,
        );
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

        MemberDatabaseNotification::send($user, function (Notification $notification) use ($outcome, $body): void {
            $notification
                ->title($outcome === 'approved' ? __('Request approved') : __('Request declined'))
                ->body($body)
                ->icon($outcome === 'approved' ? 'heroicon-o-check-circle' : 'heroicon-o-x-circle')
                ->iconColor($outcome === 'approved' ? 'success' : 'danger');
        });
    }
}
