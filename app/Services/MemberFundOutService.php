<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\FundOutRequest;
use App\Models\Tenant\Member;
use App\Notifications\Tenant\FundOutRequestAcceptedNotification;
use App\Notifications\Tenant\FundOutRequestRejectedNotification;
use App\Notifications\Tenant\NewFundOutRequestNotification;
use App\Support\BusinessDay;
use App\Support\MemberMembershipPolicy;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class MemberFundOutService
{
    private static int $notificationSuppressionDepth = 0;

    public function __construct(
        private readonly MemberFundCashTransferService $fundCashTransfers,
        private readonly OperationalReviewWorkflowService $reviewWorkflow,
    ) {}

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public static function withoutNotifications(callable $callback): mixed
    {
        self::$notificationSuppressionDepth++;

        try {
            return $callback();
        } finally {
            self::$notificationSuppressionDepth--;
        }
    }

    public static function notificationsSuppressed(): bool
    {
        return self::$notificationSuppressionDepth > 0;
    }

    public function availableFundForTransfer(Member $member, ?FundOutRequest $excludeRequest = null): float
    {
        $balance = max(0.0, round((float) ($member->fundAccount?->balance ?? $member->getFundBalance()), 2));
        $pending = $this->pendingFundOutAmount($member, $excludeRequest);

        return max(0.0, round($balance - $pending, 2));
    }

    public function submit(Member $member, float $amount, ?string $notes = null): FundOutRequest
    {
        if (! app(MemberMembershipPolicy::class)->canRequestFundOut($member)) {
            throw new InvalidArgumentException(__('Fund out is not available for this membership status.'));
        }

        if ($amount <= 0) {
            throw new InvalidArgumentException(__('Enter a transfer amount greater than zero.'));
        }

        $available = $this->availableFundForTransfer($member);

        if ($amount > $available + 0.01) {
            throw new InvalidArgumentException(__('Amount exceeds available fund balance (:available).', [
                'available' => number_format($available, 2),
            ]));
        }

        return DB::transaction(function () use ($member, $amount, $notes): FundOutRequest {
            $request = FundOutRequest::query()->create([
                'member_id' => $member->id,
                'amount' => $amount,
                'notes' => $notes,
                'status' => 'pending',
            ]);

            $this->notifyAdminsOfNewRequest($request);

            return $request;
        });
    }

    public function accept(FundOutRequest $request, ?int $reviewedBy = null, ?string $remarks = null): void
    {
        if ($request->status !== 'pending') {
            throw new InvalidArgumentException(__('Only pending fund-out requests can be accepted.'));
        }

        $request->loadMissing('member');
        $member = $request->member;
        $amount = (float) $request->amount;

        $available = $this->availableFundForTransfer($member, $request);

        if ($amount > $available + 0.01) {
            throw new InvalidArgumentException(__('Member no longer has enough available fund balance for this request.'));
        }

        DB::transaction(function () use ($request, $member, $amount, $reviewedBy, $remarks): void {
            $reviewedAt = BusinessDay::now();
            $description = __('Fund out #:id – :name', [
                'id' => $request->id,
                'name' => $member->name,
            ]);

            AccountingService::withoutMemberCashCollection(
                fn () => $this->fundCashTransfers->transferAmount(
                    $member,
                    $amount,
                    $request,
                    $description,
                    $reviewedAt,
                ),
            );

            $this->reviewWorkflow->markReviewed($request, 'accepted', $reviewedBy, $remarks, $reviewedAt);

            $this->notifyMember($request, accepted: true);
        });
    }

    public function reject(FundOutRequest $request, ?int $reviewedBy = null, ?string $remarks = null): void
    {
        if ($request->status !== 'pending') {
            throw new InvalidArgumentException(__('Only pending fund-out requests can be rejected.'));
        }

        if ($remarks === null || trim($remarks) === '') {
            throw new InvalidArgumentException(__('Provide a reason for rejection.'));
        }

        DB::transaction(function () use ($request, $reviewedBy, $remarks): void {
            $this->reviewWorkflow->markReviewed($request, 'rejected', $reviewedBy, $remarks, BusinessDay::now());

            $this->notifyMember($request, accepted: false);
        });
    }

    private function pendingFundOutAmount(Member $member, ?FundOutRequest $excludeRequest = null): float
    {
        $pendingQuery = FundOutRequest::query()
            ->where('member_id', $member->id)
            ->where('status', 'pending');

        if ($excludeRequest !== null && $excludeRequest->exists) {
            $pendingQuery->whereKeyNot($excludeRequest->getKey());
        }

        return (float) $pendingQuery->sum('amount');
    }

    private function notifyMember(FundOutRequest $request, bool $accepted): void
    {
        if (self::notificationsSuppressed()) {
            return;
        }

        $request->loadMissing('member.user');

        $notification = $accepted
            ? new FundOutRequestAcceptedNotification($request)
            : new FundOutRequestRejectedNotification($request);

        $request->member->user?->notify($notification);
    }

    private function notifyAdminsOfNewRequest(FundOutRequest $request): void
    {
        if (self::notificationsSuppressed()) {
            return;
        }

        $this->reviewWorkflow->notifyAdmins(new NewFundOutRequestNotification($request));
    }
}
