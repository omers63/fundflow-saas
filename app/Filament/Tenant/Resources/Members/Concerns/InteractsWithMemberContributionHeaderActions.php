<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\Members\Concerns;

use App\Models\Tenant\Member;
use App\Services\ContributionCycleService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;

trait InteractsWithMemberContributionHeaderActions
{
    /**
     * @return list<Action>
     */
    protected function memberContributionHeaderActions(): array
    {
        $cycles = app(ContributionCycleService::class);

        return [
            Action::make('contribute')
                ->label(__('Contribute'))
                ->icon('heroicon-o-banknotes')
                ->color('success')
                ->visible(fn (): bool => $this->record instanceof Member && $cycles->contributionCycleSelectOptionsForMember($this->record) !== [])
                ->schema([
                    Select::make('cycle')
                        ->label(__('Cycle'))
                        ->options(fn (): array => $this->record instanceof Member
                            ? $cycles->contributionCycleSelectOptionsForMember($this->record)
                            : [])
                        ->required(),
                ])
                ->fillForm(fn (): array => [
                    'cycle' => $this->record instanceof Member
                        ? $cycles->defaultContributionCycleKeyForMember($this->record)
                        : null,
                ])
                ->action(function (array $data): void {
                    if (! $this->record instanceof Member) {
                        return;
                    }

                    [$month, $year] = $cycles->parseContributionCycleKey($data['cycle']);
                    $outcome = $cycles->applyContributionForMemberForPeriod($this->record, $month, $year);

                    Notification::make()
                        ->title($outcome === 'applied' ? __('Contribution applied') : __('Could not apply'))
                        ->body(match ($outcome) {
                            'applied' => __('Posted successfully.'),
                            'insufficient' => __('Insufficient cash.'),
                            'exempt' => __('Member is exempt while loan installments are pending.'),
                            default => $outcome,
                        })
                        ->color($outcome === 'applied' ? 'success' : 'warning')
                        ->send();
                }),
            Action::make('allocateDependents')
                ->label(__('Allocate to dependents'))
                ->icon('heroicon-o-users')
                ->color('info')
                ->visible(fn (): bool => $this->record instanceof Member
                    && $this->record->dependents()->where('status', 'active')->exists())
                ->schema([
                    Select::make('cycle')
                        ->label(__('Cycle'))
                        ->options(fn (): array => $this->record instanceof Member
                            ? $cycles->contributionCycleSelectOptionsForBulk()
                            : [])
                        ->required(),
                ])
                ->action(function (array $data): void {
                    if (! $this->record instanceof Member) {
                        return;
                    }

                    [$month, $year] = $cycles->parseContributionCycleKey($data['cycle']);
                    $result = $cycles->applyDependentAllocationForParentForPeriod($this->record, $month, $year);

                    Notification::make()
                        ->title(__(':count transfer(s) completed', ['count' => $result['transfers']]))
                        ->body(implode("\n", $result['details']))
                        ->success()
                        ->send();
                }),
        ];
    }
}
