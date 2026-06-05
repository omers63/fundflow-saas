<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Filament\Member\Resources\MyContributions\MyContributionResource;
use App\Models\Tenant\Contribution;
use App\Services\ContributionCycleService;
use App\Services\ContributionService;
use App\Support\Tenant\CurrentMember;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
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

    public static function refreshContributionViews(Component $livewire): void
    {
        MyContributionResource::dispatchInsightsRefresh($livewire);

        if (method_exists($livewire, 'resetTable')) {
            $livewire->resetTable();
        }
    }
}
