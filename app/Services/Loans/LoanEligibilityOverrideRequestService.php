<?php

declare(strict_types=1);

namespace App\Services\Loans;

use App\Models\Tenant\LoanEligibilityOverrideRequest;
use App\Models\Tenant\Member;
use App\Notifications\Tenant\LoanEligibilityOverrideApprovedNotification;
use App\Notifications\Tenant\LoanEligibilityOverrideRejectedNotification;
use App\Notifications\Tenant\NewLoanEligibilityOverrideRequestNotification;
use App\Services\LoanService;
use App\Services\OperationalReviewWorkflowService;
use App\Support\LoanEligibilityGate;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class LoanEligibilityOverrideRequestService
{
    public function __construct(
        protected LoanEligibilityService $eligibility,
        protected LoanEligibilityOverrideService $overrides,
        protected LoanService $loans,
        protected OperationalReviewWorkflowService $reviewWorkflow,
    ) {}

    public function canSubmit(Member $member): bool
    {
        if (! LoanEligibilityOverrideRequest::isTableReady()) {
            return false;
        }

        $member->loadMissing('user');

        if ($member->user === null) {
            return false;
        }

        if ($this->loans->checkEligibility($member)['eligible']) {
            return false;
        }

        if ($this->pendingRequestFor($member) !== null) {
            return false;
        }

        return $this->failedGatesForRequest($member) !== [];
    }

    public function pendingRequestFor(Member $member): ?LoanEligibilityOverrideRequest
    {
        if (! LoanEligibilityOverrideRequest::isTableReady()) {
            return null;
        }

        return LoanEligibilityOverrideRequest::query()
            ->where('member_id', $member->id)
            ->pending()
            ->latest('id')
            ->first();
    }

    /**
     * @return array<string, string>
     */
    public function failedGatesForRequest(Member $member): array
    {
        return $this->eligibility->getFailedGates($member);
    }

    public function submit(Member $member, string $memberMessage): LoanEligibilityOverrideRequest
    {
        if (! LoanEligibilityOverrideRequest::isTableReady()) {
            throw new RuntimeException(__('Eligibility reviews are not available yet. Please contact the fund office.'));
        }

        $memberMessage = trim($memberMessage);

        if ($memberMessage === '') {
            throw new InvalidArgumentException(__('Please explain why you are requesting an eligibility review.'));
        }

        if (! $this->canSubmit($member)) {
            throw new InvalidArgumentException(__('You cannot submit an eligibility review request right now.'));
        }

        $failedGates = $this->failedGatesForRequest($member);

        if ($failedGates === []) {
            throw new InvalidArgumentException(__('You are already eligible for a loan.'));
        }

        return DB::transaction(function () use ($member, $memberMessage, $failedGates): LoanEligibilityOverrideRequest {
            $request = LoanEligibilityOverrideRequest::create([
                'member_id' => $member->id,
                'failed_gates' => $failedGates,
                'member_message' => $memberMessage,
                'status' => 'pending',
            ]);

            $this->reviewWorkflow->notifyAdmins(new NewLoanEligibilityOverrideRequestNotification($request));

            return $request;
        });
    }

    public function approve(
        LoanEligibilityOverrideRequest $request,
        ?int $reviewedBy = null,
        ?string $adminRemarks = null,
    ): void {
        $this->assertPending($request);

        $request->loadMissing('member.user');
        $member = $request->member;

        if ($member === null) {
            throw new RuntimeException(__('Member not found for this request.'));
        }

        $reason = $this->buildOverrideReason($request, $adminRemarks);

        DB::transaction(function () use ($request, $reviewedBy, $adminRemarks, $reason): void {
            $gates = array_values(array_unique($request->gateKeys()));

            if ($gates !== []) {
                $this->overrides->recordMany(
                    (int) $request->member_id,
                    $gates,
                    $reason,
                );
            }

            $this->reviewWorkflow->markReviewed(
                $request,
                'approved',
                $reviewedBy,
                $adminRemarks,
            );
        });

        $user = $member->user;

        if ($user !== null) {
            $user->notify(new LoanEligibilityOverrideApprovedNotification($request->fresh()));
        }
    }

    public function reject(
        LoanEligibilityOverrideRequest $request,
        ?int $reviewedBy = null,
        ?string $adminRemarks = null,
    ): void {
        $this->assertPending($request);

        $adminRemarks = trim((string) $adminRemarks);

        if ($adminRemarks === '') {
            throw new InvalidArgumentException(__('Please provide a reason for rejecting this request.'));
        }

        $request->loadMissing('member.user');

        DB::transaction(function () use ($request, $reviewedBy, $adminRemarks): void {
            $this->reviewWorkflow->markReviewed(
                $request,
                'rejected',
                $reviewedBy,
                $adminRemarks,
            );
        });

        $user = $request->member?->user;

        if ($user !== null) {
            $user->notify(new LoanEligibilityOverrideRejectedNotification($request->fresh()));
        }
    }

    /**
     * @return list<string>
     */
    public function summarizeFailedGates(LoanEligibilityOverrideRequest $request): array
    {
        $labels = LoanEligibilityGate::labels();
        $summaries = [];

        foreach ($request->failed_gates ?? [] as $gate => $reason) {
            $summaries[] = ($labels[$gate] ?? $gate).': '.$reason;
        }

        return $summaries;
    }

    protected function buildOverrideReason(
        LoanEligibilityOverrideRequest $request,
        ?string $adminRemarks,
    ): string {
        $parts = [
            __('Member request: :message', ['message' => $request->member_message]),
        ];

        if (filled($adminRemarks)) {
            $parts[] = __('Admin note: :note', ['note' => trim($adminRemarks)]);
        }

        return implode("\n\n", $parts);
    }

    protected function assertPending(LoanEligibilityOverrideRequest $request): void
    {
        if ($request->status !== 'pending') {
            throw new InvalidArgumentException(__('Only pending eligibility review requests can be updated.'));
        }
    }
}
