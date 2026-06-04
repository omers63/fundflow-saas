<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Services\Loans\LoanEligibilityOverrideRequestService;
use App\Support\Tenant\CurrentMember;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Livewire\Component;

final class RequestLoanEligibilityOverrideAction
{
    public static function make(): Action
    {
        return Action::make('requestEligibilityOverride')
            ->label(__('Request eligibility review'))
            ->icon('heroicon-o-shield-exclamation')
            ->color('warning')
            ->visible(fn (): bool => self::canRequest())
            ->modalHeading(__('Request loan eligibility review'))
            ->modalDescription(fn (): string => self::modalDescription())
            ->modalWidth('md')
            ->schema([
                Textarea::make('member_message')
                    ->label(__('Why should we review your eligibility?'))
                    ->helperText(__('Explain your situation. An administrator will review the blocked rules and respond in your notifications.'))
                    ->required()
                    ->rows(4)
                    ->maxLength(2000),
            ])
            ->action(function (array $data, Action $action): void {
                $member = CurrentMember::get();

                if ($member === null) {
                    ActionModalFailure::present(
                        $action,
                        __('We could not identify your member profile. Please sign in again or contact the fund office.'),
                        __('Could not submit eligibility review request'),
                    );
                }

                if (
                    ! ActionModalFailure::attemptThrowable(
                        $action,
                        fn () => app(LoanEligibilityOverrideRequestService::class)->submit(
                            $member,
                            (string) ($data['member_message'] ?? ''),
                        ),
                        __('Could not submit eligibility review request'),
                    )
                ) {
                    return;
                }

                Notification::make()
                    ->title(__('Request submitted'))
                    ->body(__('An administrator will review your eligibility and notify you in the app.'))
                    ->success()
                    ->send();

                $livewire = $action->getLivewire();

                if ($livewire instanceof Component) {
                    $livewire->dispatch('$refresh');
                }
            });
    }

    public static function pendingReviewAction(): Action
    {
        return Action::make('eligibilityReviewPending')
            ->label(__('Eligibility review pending'))
            ->icon('heroicon-o-clock')
            ->color('warning')
            ->disabled()
            ->visible(fn (): bool => self::hasPendingRequest());
    }

    public static function canRequest(): bool
    {
        $member = CurrentMember::get();

        if ($member === null) {
            return false;
        }

        return app(LoanEligibilityOverrideRequestService::class)->canSubmit($member);
    }

    public static function hasPendingRequest(): bool
    {
        $member = CurrentMember::get();

        if ($member === null) {
            return false;
        }

        return app(LoanEligibilityOverrideRequestService::class)->pendingRequestFor($member) !== null;
    }

    public static function modalDescription(): string
    {
        $member = CurrentMember::get();

        if ($member === null) {
            return '';
        }

        $service = app(LoanEligibilityOverrideRequestService::class);
        $failedGates = $service->failedGatesForRequest($member);

        if ($failedGates === []) {
            return __('You are already eligible for a loan.');
        }

        $lines = [__('The following eligibility rules currently block a loan application:')];

        foreach ($failedGates as $reason) {
            $lines[] = '• '.$reason;
        }

        return implode("\n\n", $lines);
    }
}
