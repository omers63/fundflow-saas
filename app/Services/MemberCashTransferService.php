<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Member;
use App\Models\Tenant\MemberCashTransferRequest;
use App\Notifications\Tenant\MemberCashTransferAcceptedNotification;
use App\Notifications\Tenant\MemberCashTransferRejectedNotification;
use App\Notifications\Tenant\NewMemberCashTransferRequestNotification;
use App\Support\MemberMembershipPolicy;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

final class MemberCashTransferService
{
    public function __construct(
        private AccountingService $accounting,
        private OperationalReviewWorkflowService $reviewWorkflow,
        private MemberCashOutService $cashOuts,
    ) {}

    public function availableCashForTransfer(Member $member, ?MemberCashTransferRequest $exclude = null): float
    {
        // Peer/dependent transfers stay inside the fund pool, so do not reserve next EMI
        // (that reserve applies to cash-out withdrawals leaving the fund).
        $balance = max(0.0, (float) ($member->cashAccount?->balance ?? 0));
        $pendingCashOuts = $this->cashOuts->pendingCashOutAmountForMember($member);
        $pendingTransfers = $this->pendingTransferAmount($member, $exclude);

        return max(0.0, round($balance - $pendingCashOuts - $pendingTransfers, 2));
    }

    public function pendingTransferAmount(Member $member, ?MemberCashTransferRequest $exclude = null): float
    {
        $query = MemberCashTransferRequest::query()
            ->where('from_member_id', $member->id)
            ->where('status', 'pending');

        if ($exclude !== null && $exclude->exists) {
            $query->whereKeyNot($exclude->getKey());
        }

        return (float) $query->sum('amount');
    }

    public function resolveRecipientByName(string $name, ?int $excludeMemberId = null): ?Member
    {
        $normalized = trim($name);

        if ($normalized === '') {
            return null;
        }

        $query = Member::query()
            ->where('status', 'active')
            ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($normalized)]);

        if ($excludeMemberId !== null) {
            $query->whereKeyNot($excludeMemberId);
        }

        $matches = $query->limit(2)->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    public function isDependentOf(Member $parent, Member $candidate): bool
    {
        return (int) ($candidate->parent_member_id ?? 0) === (int) $parent->id;
    }

    /**
     * Instant parent → dependent cash move (no admin review).
     */
    public function transferToDependent(
        Member $parent,
        Member $dependent,
        float $amount,
        ?string $notes = null,
        bool $bypassAvailabilityGuard = false,
    ): MemberCashTransferRequest {
        if (! $this->isDependentOf($parent, $dependent)) {
            throw new InvalidArgumentException(__('Cash can only be transferred to your own dependents.'));
        }

        if ($amount <= 0) {
            throw new InvalidArgumentException(__('Enter a transfer amount greater than zero.'));
        }

        if ($dependent->status !== 'active') {
            throw new InvalidArgumentException(__('Recipient membership must be active.'));
        }

        $available = $bypassAvailabilityGuard
            ? max(0.0, round((float) ($parent->cashAccount?->balance ?? 0), 2))
            : $this->availableCashForTransfer($parent);

        if ($amount > $available + 0.00001) {
            throw new InvalidArgumentException(__('Amount exceeds available cash (:available).', [
                'available' => number_format($available, 2),
            ]));
        }

        return DB::transaction(function () use ($parent, $dependent, $amount, $notes): MemberCashTransferRequest {
            $request = MemberCashTransferRequest::create([
                'from_member_id' => $parent->id,
                'to_member_id' => $dependent->id,
                'recipient_name' => $dependent->name,
                'amount' => $amount,
                'notes' => $notes,
                'status' => 'pending',
            ]);

            $this->accounting->fundDependentCashAccount(
                $parent->fresh() ?? $parent,
                $dependent->fresh() ?? $dependent,
                $amount,
                filled($notes) ? (string) $notes : '',
                triggerCollection: false,
                reference: $request,
            );

            $this->reviewWorkflow->markReviewed(
                $request,
                'accepted',
                null,
                __('Instant dependent transfer'),
            );

            return $request->fresh() ?? $request;
        });
    }

    public function submit(
        Member $from,
        float $amount,
        string $recipientName,
        ?string $notes = null,
        ?int $toMemberId = null,
        bool $bypassAvailabilityGuard = false,
    ): MemberCashTransferRequest {
        if (! $bypassAvailabilityGuard && ! app(MemberMembershipPolicy::class)->canRequestCashOut($from)) {
            throw new InvalidArgumentException(__('Cash transfers are not available for this membership status.'));
        }

        if ($amount <= 0) {
            throw new InvalidArgumentException(__('Enter a transfer amount greater than zero.'));
        }

        $recipientName = trim($recipientName);

        if ($recipientName === '') {
            throw new InvalidArgumentException(__('Enter the recipient member name.'));
        }

        if (mb_strtolower(trim((string) $from->name)) === mb_strtolower($recipientName)) {
            throw new InvalidArgumentException(__('Cannot transfer cash to yourself.'));
        }

        $to = $toMemberId !== null
            ? Member::query()->find($toMemberId)
            : $this->resolveRecipientByName($recipientName, (int) $from->id);

        if ($to !== null && (int) $to->id === (int) $from->id) {
            throw new InvalidArgumentException(__('Cannot transfer cash to yourself.'));
        }

        if ($to !== null && $to->status !== 'active') {
            throw new InvalidArgumentException(__('Recipient membership must be active.'));
        }

        // Parents move cash to their dependents immediately — no admin accept cycle.
        if ($to !== null && $this->isDependentOf($from, $to)) {
            return $this->transferToDependent(
                $from,
                $to,
                $amount,
                $notes,
                $bypassAvailabilityGuard,
            );
        }

        $available = $bypassAvailabilityGuard
            ? max(0.0, round((float) ($from->cashAccount?->balance ?? 0), 2))
            : $this->availableCashForTransfer($from);

        if ($amount > $available + 0.00001) {
            throw new InvalidArgumentException(__('Amount exceeds available cash (:available).', [
                'available' => number_format($available, 2),
            ]));
        }

        return DB::transaction(function () use ($from, $amount, $recipientName, $notes, $to): MemberCashTransferRequest {
            $request = MemberCashTransferRequest::create([
                'from_member_id' => $from->id,
                'to_member_id' => $to?->id,
                'recipient_name' => $to?->name ?? $recipientName,
                'amount' => $amount,
                'notes' => $notes,
                'status' => 'pending',
            ]);

            $this->notifyAdminsOfNewRequest($request);

            return $request;
        });
    }

    public function accept(
        MemberCashTransferRequest $request,
        ?int $reviewedBy = null,
        ?string $remarks = null,
        ?int $toMemberId = null,
    ): void {
        if ($request->status !== 'pending') {
            throw new InvalidArgumentException(__('Only pending transfer requests can be accepted.'));
        }

        $from = $request->fromMember;

        if ($from === null) {
            throw new InvalidArgumentException(__('Transfer has no source member.'));
        }

        $toId = $toMemberId ?? $request->to_member_id;
        $to = $toId !== null ? Member::query()->find($toId) : null;

        if ($to === null) {
            $to = $this->resolveRecipientByName((string) $request->recipient_name, (int) $from->id);
        }

        if ($to === null) {
            throw new InvalidArgumentException(__('Select the recipient member before accepting.'));
        }

        if ((int) $to->id === (int) $from->id) {
            throw new InvalidArgumentException(__('Cannot transfer cash to the same member.'));
        }

        if ($to->status !== 'active') {
            throw new InvalidArgumentException(__('Recipient membership must be active.'));
        }

        $amount = (float) $request->amount;

        if ((float) ($from->fresh()->cashAccount?->balance ?? 0) < $amount - 0.00001) {
            throw new RuntimeException(__('Insufficient member cash balance for this transfer.'));
        }

        DB::transaction(function () use ($request, $from, $to, $amount, $reviewedBy, $remarks): void {
            $request->update([
                'to_member_id' => $to->id,
                'recipient_name' => $to->name,
            ]);

            $this->accounting->transferPeerMemberCash(
                $from->fresh(),
                $to->fresh(),
                $amount,
                filled($request->notes) ? (string) $request->notes : '',
                $request,
            );

            $this->reviewWorkflow->markReviewed(
                $request,
                'accepted',
                $reviewedBy,
                $remarks,
            );
        });

        $this->notifyMembersAboutOutcome($request->fresh(), accepted: true);
    }

    public function reject(
        MemberCashTransferRequest $request,
        ?int $reviewedBy = null,
        ?string $remarks = null,
    ): void {
        if ($request->status !== 'pending') {
            throw new InvalidArgumentException(__('Only pending transfer requests can be rejected.'));
        }

        if (! filled($remarks)) {
            throw new InvalidArgumentException(__('Remarks are required when rejecting a transfer.'));
        }

        $this->reviewWorkflow->markReviewed($request, 'rejected', $reviewedBy, $remarks);
        $this->notifyMembersAboutOutcome($request->fresh(), accepted: false);
    }

    public function cancel(
        MemberCashTransferRequest $request,
        ?int $cancelledBy = null,
        ?string $remarks = null,
    ): void {
        if ($request->status !== 'pending') {
            throw new InvalidArgumentException(__('Only pending transfer requests can be cancelled.'));
        }

        $this->reviewWorkflow->markReviewed(
            $request,
            'cancelled',
            $cancelledBy,
            $remarks ?? __('Cancelled by member'),
        );
    }

    private function notifyAdminsOfNewRequest(MemberCashTransferRequest $request): void
    {
        try {
            $this->reviewWorkflow->notifyAdmins(new NewMemberCashTransferRequestNotification($request));
        } catch (\Throwable $e) {
            logger()->warning('MemberCashTransferService: admin notification failed', [
                'request_id' => $request->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function notifyMembersAboutOutcome(MemberCashTransferRequest $request, bool $accepted): void
    {
        try {
            $request->loadMissing(['fromMember.user', 'toMember.user']);
            $fromUser = $request->fromMember?->user;

            if ($fromUser === null) {
                return;
            }

            if ($accepted) {
                $fromUser->notify(new MemberCashTransferAcceptedNotification($request));
                $request->toMember?->user?->notify(new MemberCashTransferAcceptedNotification($request, forRecipient: true));
            } else {
                $fromUser->notify(new MemberCashTransferRejectedNotification($request));
            }
        } catch (\Throwable $e) {
            logger()->warning('MemberCashTransferService: member notification failed', [
                'request_id' => $request->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
