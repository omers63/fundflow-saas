<?php

declare(strict_types=1);

namespace App\Services\Loans;

use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanGuarantorReplacementRequest;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Notifications\Tenant\MemberStatusChangedNotification;
use App\Notifications\Tenant\NewGuarantorReplacementNominationNotification;
use App\Services\OperationalReviewWorkflowService;
use App\Support\MemberMembershipPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class LoanGuarantorReplacementService
{
    public function __construct(
        private readonly MemberMembershipPolicy $policy,
        private readonly OperationalReviewWorkflowService $reviewWorkflow,
    ) {}

    public function hasAcceptedReplacement(Loan $loan, Member $outgoingGuarantor): bool
    {
        if ((int) $loan->guarantor_member_id !== (int) $outgoingGuarantor->id) {
            // Already replaced on the loan itself.
            return true;
        }

        return LoanGuarantorReplacementRequest::query()
            ->where('loan_id', $loan->id)
            ->where('outgoing_guarantor_member_id', $outgoingGuarantor->id)
            ->where('status', LoanGuarantorReplacementRequest::STATUS_ACCEPTED)
            ->exists();
    }

    /**
     * Member portal: nominate a guarantor by name for admin matching (no member directory).
     */
    public function requestByName(
        Loan $loan,
        string $proposedGuarantorName,
        string $noteToAdmin,
        User $actor,
        ?int $freezeMemberRequestId = null,
    ): LoanGuarantorReplacementRequest {
        $this->assertLoanCanChangeGuarantor($loan);

        $outgoing = $loan->guarantor;
        if (! $outgoing instanceof Member) {
            throw new InvalidArgumentException(__('This loan has no guarantor to replace.'));
        }

        if (
            (int) $actor->activeMember()?->id !== (int) $loan->member_id
            && ! $actor->is_admin
        ) {
            throw ValidationException::withMessages([
                'loan' => __('Only the borrower or an administrator can propose a replacement guarantor.'),
            ]);
        }

        $name = trim($proposedGuarantorName);
        $note = trim($noteToAdmin);

        if ($name === '') {
            throw ValidationException::withMessages([
                'proposed_guarantor_name' => __('Enter the name of the member you want as guarantor.'),
            ]);
        }

        if ($note === '') {
            throw ValidationException::withMessages([
                'note' => __('Add a short note for the administrator.'),
            ]);
        }

        return DB::transaction(function () use ($loan, $outgoing, $actor, $name, $note, $freezeMemberRequestId): LoanGuarantorReplacementRequest {
            $this->cancelOpenRequestsForLoan($loan);

            $request = LoanGuarantorReplacementRequest::query()->create([
                'loan_id' => $loan->id,
                'outgoing_guarantor_member_id' => $outgoing->id,
                'proposed_guarantor_member_id' => null,
                'proposed_guarantor_name' => $name,
                'borrower_member_id' => $loan->member_id,
                'proposed_by_user_id' => $actor->id,
                'proposed_by_role' => LoanGuarantorReplacementRequest::ROLE_BORROWER,
                'status' => LoanGuarantorReplacementRequest::STATUS_PENDING_ADMIN,
                'freeze_member_request_id' => $freezeMemberRequestId,
                'note' => $note,
            ]);

            $this->reviewWorkflow->notifyAdmins(new NewGuarantorReplacementNominationNotification($request));

            return $request;
        });
    }

    /**
     * Admin matches a pending name nomination to a real member; proposed guarantor must then accept.
     */
    public function assignProposedGuarantor(
        LoanGuarantorReplacementRequest $request,
        Member $proposedGuarantor,
        User $admin,
    ): LoanGuarantorReplacementRequest {
        if (! $request->isPendingAdmin()) {
            throw ValidationException::withMessages([
                'status' => __('This nomination is no longer waiting for admin matching.'),
            ]);
        }

        if (! $admin->is_admin) {
            throw ValidationException::withMessages([
                'admin' => __('Only an administrator can match the nominated guarantor.'),
            ]);
        }

        $loan = $request->loan ?? Loan::query()->findOrFail($request->loan_id);
        $this->assertProposedGuarantorEligible($loan, $proposedGuarantor);

        return DB::transaction(function () use ($request, $proposedGuarantor, $loan): LoanGuarantorReplacementRequest {
            $request->update([
                'proposed_guarantor_member_id' => $proposedGuarantor->id,
                'proposed_guarantor_name' => $proposedGuarantor->name,
                'status' => LoanGuarantorReplacementRequest::STATUS_PENDING_GUARANTOR,
            ]);

            $proposedGuarantor->loadMissing('user');
            $proposedGuarantor->user?->notify(new MemberStatusChangedNotification(
                $proposedGuarantor,
                'active',
                __('Guarantor acceptance required'),
                __('You have been proposed as guarantor for loan #:id (borrower: :name). Please accept or decline.', [
                    'id' => $loan->id,
                    'name' => $loan->member?->name ?? __('Member'),
                ]),
            ));

            return $request->fresh() ?? $request;
        });
    }

    /**
     * Admin (or system) proposes a concrete guarantor; proposed guarantor must accept.
     */
    public function propose(
        Loan $loan,
        Member $proposedGuarantor,
        User $actor,
        string $role,
        ?int $freezeMemberRequestId = null,
        ?string $note = null,
    ): LoanGuarantorReplacementRequest {
        $this->assertLoanCanChangeGuarantor($loan);

        $outgoing = $loan->guarantor;
        if (! $outgoing instanceof Member) {
            throw new InvalidArgumentException(__('This loan has no guarantor to replace.'));
        }

        if (
            $role === LoanGuarantorReplacementRequest::ROLE_BORROWER
            && (int) $actor->activeMember()?->id !== (int) $loan->member_id
            && ! $actor->is_admin
        ) {
            throw ValidationException::withMessages([
                'loan' => __('Only the borrower or an administrator can propose a replacement guarantor.'),
            ]);
        }

        if ($role === LoanGuarantorReplacementRequest::ROLE_BORROWER && ! $actor->is_admin) {
            throw ValidationException::withMessages([
                'guarantor' => __('Borrowers nominate a guarantor by name for admin review. They cannot pick from the member directory.'),
            ]);
        }

        $this->assertProposedGuarantorEligible($loan, $proposedGuarantor);

        return DB::transaction(function () use ($loan, $outgoing, $proposedGuarantor, $actor, $role, $freezeMemberRequestId, $note): LoanGuarantorReplacementRequest {
            $this->cancelOpenRequestsForLoan($loan);

            $request = LoanGuarantorReplacementRequest::query()->create([
                'loan_id' => $loan->id,
                'outgoing_guarantor_member_id' => $outgoing->id,
                'proposed_guarantor_member_id' => $proposedGuarantor->id,
                'proposed_guarantor_name' => $proposedGuarantor->name,
                'borrower_member_id' => $loan->member_id,
                'proposed_by_user_id' => $actor->id,
                'proposed_by_role' => $role,
                'status' => LoanGuarantorReplacementRequest::STATUS_PENDING_GUARANTOR,
                'freeze_member_request_id' => $freezeMemberRequestId,
                'note' => $note,
            ]);

            $proposedGuarantor->loadMissing('user');
            $proposedGuarantor->user?->notify(new MemberStatusChangedNotification(
                $proposedGuarantor,
                'active',
                __('Guarantor acceptance required'),
                __('You have been proposed as guarantor for loan #:id (borrower: :name). Please accept or decline.', [
                    'id' => $loan->id,
                    'name' => $loan->member?->name ?? __('Member'),
                ]),
            ));

            return $request;
        });
    }

    public function accept(LoanGuarantorReplacementRequest $request, Member $actingGuarantor): void
    {
        if (! $request->isPendingGuarantor()) {
            throw ValidationException::withMessages([
                'status' => __('This replacement request is no longer pending guarantor acceptance.'),
            ]);
        }

        if ((int) $actingGuarantor->id !== (int) $request->proposed_guarantor_member_id) {
            throw ValidationException::withMessages([
                'guarantor' => __('Only the proposed guarantor can accept this request.'),
            ]);
        }

        DB::transaction(function () use ($request, $actingGuarantor): void {
            $loan = Loan::query()->lockForUpdate()->findOrFail($request->loan_id);

            $loan->update([
                'guarantor_member_id' => $actingGuarantor->id,
                'guarantor_name' => $actingGuarantor->name,
            ]);

            $request->update([
                'status' => LoanGuarantorReplacementRequest::STATUS_ACCEPTED,
                'accepted_at' => now(),
            ]);

            $borrower = $loan->member;
            $borrower?->loadMissing('user');
            $borrower?->user?->notify(new MemberStatusChangedNotification(
                $borrower,
                'active',
                __('New guarantor accepted'),
                __(':name accepted as guarantor for loan #:id.', [
                    'name' => $actingGuarantor->name,
                    'id' => $loan->id,
                ]),
            ));
        });
    }

    public function reject(LoanGuarantorReplacementRequest $request, Member $actingGuarantor, ?string $note = null): void
    {
        if (! $request->isPendingGuarantor()) {
            throw ValidationException::withMessages([
                'status' => __('This replacement request is no longer pending guarantor acceptance.'),
            ]);
        }

        if ((int) $actingGuarantor->id !== (int) $request->proposed_guarantor_member_id) {
            throw ValidationException::withMessages([
                'guarantor' => __('Only the proposed guarantor can decline this request.'),
            ]);
        }

        $request->update([
            'status' => LoanGuarantorReplacementRequest::STATUS_REJECTED,
            'rejected_at' => now(),
            'note' => $note ?? $request->note,
        ]);
    }

    /**
     * Outstanding loans where $guarantor is still the guarantor (no accepted replacement).
     *
     * @return list<Loan>
     */
    public function unresolvedLoansForOutgoingGuarantor(Member $guarantor): array
    {
        return Loan::query()
            ->where('guarantor_member_id', $guarantor->id)
            ->whereIn('status', ['active', 'transferred', 'partially_disbursed', 'approved', 'pending'])
            ->with('member.user')
            ->get()
            ->filter(fn (Loan $loan): bool => ! $this->hasAcceptedReplacement($loan, $guarantor))
            ->values()
            ->all();
    }

    public function latestOpenRequestForLoan(Loan $loan): ?LoanGuarantorReplacementRequest
    {
        return LoanGuarantorReplacementRequest::query()
            ->where('loan_id', $loan->id)
            ->whereIn('status', [
                LoanGuarantorReplacementRequest::STATUS_PENDING_ADMIN,
                LoanGuarantorReplacementRequest::STATUS_PENDING_GUARANTOR,
            ])
            ->latest('id')
            ->first();
    }

    private function assertLoanCanChangeGuarantor(Loan $loan): void
    {
        if (! in_array($loan->status, ['active', 'transferred', 'partially_disbursed', 'approved', 'pending'], true)) {
            throw new InvalidArgumentException(__('This loan cannot change guarantors in its current status.'));
        }
    }

    private function assertProposedGuarantorEligible(Loan $loan, Member $proposedGuarantor): void
    {
        $outgoing = $loan->guarantor;

        if ($outgoing instanceof Member && (int) $proposedGuarantor->id === (int) $outgoing->id) {
            throw ValidationException::withMessages([
                'guarantor' => __('Choose a different member as the new guarantor.'),
            ]);
        }

        if ((int) $proposedGuarantor->id === (int) $loan->member_id) {
            throw ValidationException::withMessages([
                'guarantor' => __('The borrower cannot guarantee their own loan.'),
            ]);
        }

        if (! $this->policy->canBeGuarantor($proposedGuarantor)) {
            throw ValidationException::withMessages([
                'guarantor' => __('The selected member is not eligible to be a guarantor.'),
            ]);
        }
    }

    private function cancelOpenRequestsForLoan(Loan $loan): void
    {
        LoanGuarantorReplacementRequest::query()
            ->where('loan_id', $loan->id)
            ->whereIn('status', [
                LoanGuarantorReplacementRequest::STATUS_PENDING_ADMIN,
                LoanGuarantorReplacementRequest::STATUS_PENDING_GUARANTOR,
            ])
            ->update([
                'status' => LoanGuarantorReplacementRequest::STATUS_CANCELLED,
                'rejected_at' => now(),
            ]);
    }
}
