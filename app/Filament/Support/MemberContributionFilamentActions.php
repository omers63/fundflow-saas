<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Filament\Member\Resources\MyContributions\MyContributionResource;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\Member;
use App\Models\Tenant\MemberRequest;
use App\Services\ContributionCycleService;
use App\Services\ContributionService;
use App\Services\Tenant\MemberRequestService;
use App\Support\Tenant\CurrentMember;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

final class MemberContributionFilamentActions
{
    public static function applyOpenPeriodContribution(): Action
    {
        return Action::make('applyOpenPeriodContribution')
            ->label(__('Apply this period'))
            ->icon('heroicon-o-currency-dollar')
            ->color('primary')
            ->visible(function (): bool {
                $member = CurrentMember::get();

                if ($member === null) {
                    return false;
                }

                try {
                    [$month, $year] = app(ContributionCycleService::class)->currentOpenPeriod();
                } catch (\Throwable) {
                    return false;
                }

                if ($member->isExemptFromContributions($month, $year)) {
                    return false;
                }

                if ((float) $member->monthly_contribution_amount <= 0) {
                    return false;
                }

                if (Contribution::periodFullyPosted($member->id, $month, $year)) {
                    return false;
                }

                return true;
            })
            ->requiresConfirmation()
            ->modalHeading(__('Apply contribution for this period from cash'))
            ->modalDescription(__('Create and post your monthly contribution for the open cycle using your cash account.'))
            ->action(function (Component $livewire): void {
                $member = CurrentMember::get();

                if ($member === null) {
                    return;
                }

                try {
                    [$month, $year] = app(ContributionCycleService::class)->currentOpenPeriod();
                } catch (\Throwable) {
                    return;
                }

                $results = [
                    'applied' => [],
                    'insufficient' => [],
                    'skipped' => [],
                ];

                $outcome = app(ContributionService::class)->applyForPeriod($member, $month, $year, $results);

                $notification = Notification::make()
                    ->title(match ($outcome) {
                        'applied', 'partial' => __('Contribution applied'),
                        'insufficient' => __('Insufficient cash balance'),
                        'already_contributed' => __('Already contributed for this period'),
                        'exempt' => __('Contributions are paused'),
                        default => __('Nothing to apply'),
                    });

                $body = match ($outcome) {
                    'partial' => __('We applied as much as possible from your cash balance.'),
                    'insufficient' => __('Your cash balance is too low to apply this contribution.'),
                    'already_contributed' => __('This period already has a posted contribution.'),
                    'exempt' => __('You are exempt from contributions for this period.'),
                    default => null,
                };

                if ($body !== null) {
                    $notification->body($body);
                }

                match ($outcome) {
                    'applied', 'partial' => $notification->success(),
                    'insufficient' => $notification->warning(),
                    default => $notification->info(),
                };

                $notification->send();

                self::refreshContributionViews($livewire);
            });
    }

    public static function requestOpenCycleAmount(?Member $forDependent = null): Action
    {
        $cycles = app(ContributionCycleService::class);
        $actionName = $forDependent !== null
            ? 'requestOpenCycleAmountForDependent'
            : 'requestOpenCycleAmount';

        return Action::make($actionName)
            ->label(__('Request larger cycle amount'))
            ->icon('heroicon-o-arrow-trending-up')
            ->color('warning')
            ->visible(fn (): bool => self::canRequestOpenCycleAmount($forDependent))
            ->modalHeading(__('Request larger amount for this cycle'))
            ->modalDescription(function () use ($forDependent, $cycles): string {
                [$month, $year] = $cycles->currentOpenPeriod();
                $period = $cycles->periodLabel($month, $year);
                $member = CurrentMember::get();
                $target = $forDependent ?? $member;
                $standing = number_format((float) ($target?->monthly_contribution_amount ?? 0), 2);

                return __('Ask administrators to replace this cycle’s contribution due with a larger amount for :period. Your standing monthly allocation (:amount) stays unchanged for future cycles.', [
                    'period' => $period,
                    'amount' => $standing,
                ]);
            })
            ->modalSubmitActionLabel(__('Submit request'))
            ->schema(function () use ($forDependent): array {
                $member = CurrentMember::get();
                $target = $forDependent ?? $member;
                $min = (float) ($target?->monthly_contribution_amount ?? 0) + 0.01;

                return [
                    TextInput::make('amount')
                        ->label(__('Requested amount'))
                        ->numeric()
                        ->required()
                        ->minValue($min)
                        ->helperText(__('Must be greater than the member’s current monthly allocation.')),
                    Textarea::make('note')
                        ->label(__('Note (optional)'))
                        ->rows(3)
                        ->maxLength(500),
                ];
            })
            ->action(function (array $data) use ($forDependent): void {
                $member = CurrentMember::get();

                if ($member === null) {
                    return;
                }

                try {
                    app(MemberRequestService::class)->submit($member, MemberRequest::TYPE_OPEN_CYCLE_CONTRIBUTION, [
                        'amount' => $data['amount'],
                        'note' => $data['note'] ?? null,
                        'target_member_id' => ($forDependent ?? $member)->id,
                    ]);

                    Notification::make()
                        ->title(__('Request submitted'))
                        ->body(__('Administrators will review your open-cycle contribution amount request.'))
                        ->success()
                        ->send();
                } catch (ValidationException $exception) {
                    $message = collect($exception->errors())->flatten()->first() ?? $exception->getMessage();

                    Notification::make()
                        ->title(__('Could not submit'))
                        ->body($message)
                        ->danger()
                        ->send();
                }
            });
    }

    public static function canRequestOpenCycleAmount(?Member $forDependent = null): bool
    {
        $member = CurrentMember::get();

        if ($member === null || $member->status !== 'active') {
            return false;
        }

        $cycles = app(ContributionCycleService::class);

        try {
            [$month, $year] = $cycles->currentOpenPeriod();
        } catch (\Throwable) {
            return false;
        }

        $target = $forDependent ?? $member;

        if ($target->status !== 'active') {
            return false;
        }

        if ($forDependent !== null && (int) $target->parent_member_id !== (int) $member->id) {
            return false;
        }

        if (! $cycles->memberIsLiableForContributionPeriod($target, $month, $year)) {
            return false;
        }

        return ! Contribution::periodFullyPosted($target->id, $month, $year);
    }

    /**
     * Row action for a household dependent (parent requesting on their behalf).
     */
    public static function requestOpenCycleAmountForDependentRow(): Action
    {
        $cycles = app(ContributionCycleService::class);

        return Action::make('requestOpenCycleAmountForDependent')
            ->label(__('Request larger cycle amount'))
            ->icon('heroicon-o-arrow-trending-up')
            ->color('warning')
            ->visible(fn (Member $record): bool => self::canRequestOpenCycleAmount($record))
            ->modalHeading(__('Request larger amount for this cycle'))
            ->modalDescription(function (Member $record) use ($cycles): string {
                [$month, $year] = $cycles->currentOpenPeriod();

                return __('Ask administrators to replace this cycle’s contribution due with a larger amount for :period. Standing monthly allocation (:amount) for :name stays unchanged for future cycles.', [
                    'period' => $cycles->periodLabel($month, $year),
                    'amount' => number_format((float) $record->monthly_contribution_amount, 2),
                    'name' => $record->name,
                ]);
            })
            ->modalSubmitActionLabel(__('Submit request'))
            ->schema(fn (Member $record): array => [
                TextInput::make('amount')
                    ->label(__('Requested amount'))
                    ->numeric()
                    ->required()
                    ->minValue((float) $record->monthly_contribution_amount + 0.01)
                    ->helperText(__('Must be greater than the member’s current monthly allocation.')),
                Textarea::make('note')
                    ->label(__('Note (optional)'))
                    ->rows(3)
                    ->maxLength(500),
            ])
            ->action(function (Member $record, array $data): void {
                $parent = CurrentMember::get();

                if ($parent === null) {
                    return;
                }

                try {
                    app(MemberRequestService::class)->submit($parent, MemberRequest::TYPE_OPEN_CYCLE_CONTRIBUTION, [
                        'amount' => $data['amount'],
                        'note' => $data['note'] ?? null,
                        'target_member_id' => $record->id,
                    ]);

                    Notification::make()
                        ->title(__('Request submitted'))
                        ->body(__('Administrators will review your open-cycle contribution amount request.'))
                        ->success()
                        ->send();
                } catch (ValidationException $exception) {
                    $message = collect($exception->errors())->flatten()->first() ?? $exception->getMessage();

                    Notification::make()
                        ->title(__('Could not submit'))
                        ->body($message)
                        ->danger()
                        ->send();
                }
            });
    }

    public static function refreshContributionViews(Component $livewire): void
    {
        MyContributionResource::dispatchInsightsRefresh($livewire);

        if (method_exists($livewire, 'resetTable')) {
            $livewire->resetTable();
        }
    }
}
