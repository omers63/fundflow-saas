<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Tenant\Loan;
use App\Services\Loans\LoanEarlySettlementService;
use App\Services\Loans\LoanRepaymentService;
use App\Services\LoanService;
use App\Support\Tenant\CurrentMember;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

final class MemberLoanFilamentActions
{
    public static function payOpenPeriodRepayment(): Action
    {
        return Action::make('payOpenPeriodRepayment')
            ->label(__('Pay this period'))
            ->icon('heroicon-o-currency-dollar')
            ->color('primary')
            ->visible(function (Loan $record): bool {
                $member = CurrentMember::get();

                return $member !== null
                    && (int) $record->member_id === (int) $member->id
                    && $record->status === 'active'
                    && app(LoanRepaymentService::class)->shouldOfferOpenPeriodRepayment($member);
            })
            ->requiresConfirmation()
            ->modalHeading(__('Pay loan installment from cash'))
            ->modalDescription(function (Loan $record): string {
                $member = CurrentMember::get();

                return $member
                    ? app(LoanRepaymentService::class)->openPeriodRepaymentModalDescription($member)
                    : '';
            })
            ->action(function (Loan $record): void {
                $member = CurrentMember::get();

                if ($member === null || (int) $record->member_id !== (int) $member->id) {
                    return;
                }

                $result = app(LoanRepaymentService::class)->applyOpenPeriodRepaymentForMember($member);

                $repayments = app(LoanRepaymentService::class);

                $notification = Notification::make()
                    ->title(match ($result) {
                        'applied' => __('Payment applied'),
                        'insufficient' => __('Insufficient cash balance'),
                        default => __('Nothing to pay'),
                    })
                    ->body($result === 'skipped'
                        ? $repayments->openPeriodSkipMessage($member)
                        : null);

                match ($result) {
                    'applied' => $notification->success(),
                    'insufficient' => $notification->warning(),
                    default => $notification->info(),
                };

                $notification->send();
            });
    }

    public static function earlySettle(): Action
    {
        return Action::make('earlySettle')
            ->label(__('Early settlement'))
            ->icon('heroicon-o-check-badge')
            ->color('success')
            ->visible(function (Loan $record): bool {
                $member = CurrentMember::get();

                return $member !== null
                    && (int) $record->member_id === (int) $member->id
                    && $record->status === 'active';
            })
            ->modalHeading(__('Early loan settlement'))
            ->fillForm(fn (Loan $record): array => [
                'amount' => app(LoanEarlySettlementService::class)->requiredCash($record),
                'option' => 'roll_up',
            ])
            ->schema(fn (Loan $record): array => LoanFilamentActions::earlySettlementFormSchema($record))
            ->action(function (Loan $record, array $data, Action $action, LoanService $loanService): void {
                $member = CurrentMember::get();

                if ($member === null || (int) $record->member_id !== (int) $member->id) {
                    return;
                }

                if (
                    ! ActionModalFailure::attemptThrowable(
                        $action,
                        fn () => $loanService->settleLoan(
                            $record,
                            (float) $data['amount'],
                            (string) ($data['option'] ?? 'roll_up'),
                        ),
                        __('Settlement failed'),
                    )
                ) {
                    return;
                }

                Notification::make()
                    ->title(__('Early settlement applied'))
                    ->body(__('Your settlement has been recorded. Thank you.'))
                    ->success()
                    ->send();
            });
    }
}
