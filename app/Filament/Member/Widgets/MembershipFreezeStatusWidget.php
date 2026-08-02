<?php

declare(strict_types=1);

namespace App\Filament\Member\Widgets;

use App\Models\Tenant\LoanGuarantorReplacementRequest;
use App\Services\Loans\LoanGuarantorReplacementService;
use App\Services\MemberFreezeService;
use App\Support\MemberMembershipPolicy;
use App\Support\Tenant\CurrentMember;
use Filament\Notifications\Notification;
use Filament\Widgets\Widget;
use Illuminate\Validation\ValidationException;

class MembershipFreezeStatusWidget extends Widget
{
    protected string $view = 'filament.member.widgets.membership-freeze-status';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        $member = CurrentMember::get();

        if ($member === null) {
            return false;
        }

        $policy = app(MemberMembershipPolicy::class);

        if ($policy->isFrozen($member)) {
            return true;
        }

        if (app(LoanGuarantorReplacementService::class)->unresolvedLoansForOutgoingGuarantor($member) !== []) {
            return true;
        }

        return LoanGuarantorReplacementRequest::query()
            ->where('proposed_guarantor_member_id', $member->id)
            ->where('status', LoanGuarantorReplacementRequest::STATUS_PENDING_GUARANTOR)
            ->exists();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $member = CurrentMember::get();
        $freezes = app(MemberFreezeService::class);

        $pendingAccept = $member === null ? collect() : LoanGuarantorReplacementRequest::query()
            ->with(['loan.member', 'outgoingGuarantor'])
            ->where('proposed_guarantor_member_id', $member->id)
            ->where('status', LoanGuarantorReplacementRequest::STATUS_PENDING_GUARANTOR)
            ->get();

        $needsReplacement = $member === null ? [] : app(LoanGuarantorReplacementService::class)
            ->unresolvedLoansForOutgoingGuarantor($member);

        return [
            'member' => $member,
            'isFrozen' => $member !== null && $freezes->isFrozen($member),
            'withinPlan' => $member !== null && $freezes->isWithinFreezePlan($member),
            'planExhausted' => $member !== null && $freezes->isFreezePlanExhausted($member),
            'pendingAccept' => $pendingAccept,
            'needsReplacement' => $needsReplacement,
        ];
    }

    public function notifyBorrowersToReplaceGuarantor(): void
    {
        $member = CurrentMember::get();
        if ($member === null) {
            return;
        }

        try {
            $result = app(MemberFreezeService::class)->notifyBorrowersToReplaceGuarantor($member);

            Notification::make()
                ->title(__('Borrowers notified'))
                ->body(__('Notified :count borrower(s) to replace you as guarantor.', [
                    'count' => $result['notified'],
                ]))
                ->success()
                ->send();
        } catch (ValidationException $exception) {
            Notification::make()
                ->title(__('Could not notify'))
                ->body(collect($exception->errors())->flatten()->first())
                ->danger()
                ->send();
        }
    }

    public function acceptGuarantorReplacement(int $requestId): void
    {
        $member = CurrentMember::get();
        if ($member === null) {
            return;
        }

        $request = LoanGuarantorReplacementRequest::query()->findOrFail($requestId);

        try {
            app(LoanGuarantorReplacementService::class)->accept($request, $member);
            Notification::make()->title(__('Guarantor role accepted'))->success()->send();
        } catch (ValidationException $exception) {
            Notification::make()
                ->title(__('Could not accept'))
                ->body(collect($exception->errors())->flatten()->first())
                ->danger()
                ->send();
        }
    }

    public function rejectGuarantorReplacement(int $requestId): void
    {
        $member = CurrentMember::get();
        if ($member === null) {
            return;
        }

        $request = LoanGuarantorReplacementRequest::query()->findOrFail($requestId);

        try {
            app(LoanGuarantorReplacementService::class)->reject($request, $member);
            Notification::make()->title(__('Guarantor proposal declined'))->success()->send();
        } catch (ValidationException $exception) {
            Notification::make()
                ->title(__('Could not decline'))
                ->body(collect($exception->errors())->flatten()->first())
                ->danger()
                ->send();
        }
    }
}
