<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\Members\Concerns;

use App\Filament\Support\MemberDelinquencyActions;
use App\Filament\Tenant\Resources\Members\MemberResource;
use App\Models\Tenant\Member;
use App\Services\ContributionCycleService;
use App\Services\MigrationCycleService;
use App\Services\MigrationOpeningBalanceService;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
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

                    if ($outcome === 'applied') {
                        MemberResource::dispatchMemberDetailInsightsRefresh($this);
                    }
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

    /**
     * @return list<Action|ActionGroup>
     */
    protected function organizedMemberHeaderActions(): array
    {
        $contributionActions = $this->memberContributionHeaderActions();
        $actions = [];

        if (isset($contributionActions[0])) {
            $actions[] = $contributionActions[0];
        }

        $householdActions = array_slice($contributionActions, 1);

        if ($householdActions !== []) {
            $actions[] = ActionGroup::make($householdActions)
                ->label(__('Household'))
                ->icon('heroicon-o-users')
                ->color('gray')
                ->button();
        }

        $delinquencyActions = MemberDelinquencyActions::forMemberRecord();

        if ($delinquencyActions !== []) {
            $actions[] = ActionGroup::make($delinquencyActions)
                ->label(__('Delinquency'))
                ->icon('heroicon-o-exclamation-triangle')
                ->color('gray')
                ->button();
        }

        $migrationActions = $this->memberMigrationHeaderActions();

        if ($migrationActions !== []) {
            $actions[] = ActionGroup::make($migrationActions)
                ->label(__('Migration'))
                ->icon('heroicon-o-clock')
                ->color('gray')
                ->button();
        }

        return $actions;
    }

    /**
     * @return list<Action>
     */
    protected function memberMigrationHeaderActions(): array
    {
        return [
            Action::make('generateMigrationStubs')
                ->label(__('Generate migration stubs'))
                ->icon('heroicon-o-document-plus')
                ->visible(fn (): bool => $this->record instanceof Member)
                ->schema([
                    DatePicker::make('cutoff')
                        ->label(__('Cutoff date'))
                        ->default(fn (): ?string => $this->record instanceof Member
                            ? ($this->record->migration_cutoff_date?->toDateString() ?? now()->startOfMonth()->toDateString())
                            : null)
                        ->required(),
                ])
                ->action(function (array $data, MigrationCycleService $migration): void {
                    if (! $this->record instanceof Member) {
                        return;
                    }

                    try {
                        $count = $migration->generateHistoricalStubs(
                            $this->record,
                            Carbon::parse((string) $data['cutoff']),
                        );
                    } catch (\InvalidArgumentException $exception) {
                        Notification::make()->title(__('Cannot generate stubs'))->body($exception->getMessage())->danger()->send();

                        return;
                    }

                    Notification::make()
                        ->title(__(':count stub(s) created', ['count' => $count]))
                        ->success()
                        ->send();
                }),
            Action::make('postOpeningBalances')
                ->label(__('Post opening balances'))
                ->icon('heroicon-o-banknotes')
                ->visible(fn (): bool => $this->record instanceof Member
                    && $this->record->opening_balances_posted_at === null)
                ->schema([
                    TextInput::make('opening_cash_balance')
                        ->label(__('Opening cash'))
                        ->numeric()
                        ->default(fn (): float => (float) ($this->record?->getCashBalance() ?? 0)),
                    TextInput::make('opening_fund_balance')
                        ->label(__('Opening fund'))
                        ->numeric()
                        ->default(fn (): float => (float) ($this->record?->getFundBalance() ?? 0)),
                ])
                ->action(function (array $data, MigrationOpeningBalanceService $openings): void {
                    if (! $this->record instanceof Member) {
                        return;
                    }

                    try {
                        $openings->postOpeningBalances(
                            $this->record,
                            (float) ($data['opening_cash_balance'] ?? 0),
                            (float) ($data['opening_fund_balance'] ?? 0),
                        );
                    } catch (\InvalidArgumentException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();

                        return;
                    }

                    Notification::make()->title(__('Opening balances posted'))->success()->send();
                    $this->refreshResolvedRecord();
                }),
            Action::make('grantPartialClearance')
                ->label(__('Grant partial clearance'))
                ->icon('heroicon-o-shield-check')
                ->color('warning')
                ->requiresConfirmation()
                ->modalDescription(__('Allows active operation while escalated historical cycles remain under investigation. Use only for long migration histories.'))
                ->visible(fn (): bool => $this->record instanceof Member
                    && $this->record->migration_status === 'migration_pending'
                    && $this->record->partial_clearance_granted_at === null)
                ->schema([
                    Textarea::make('notes')
                        ->label(__('Reason'))
                        ->required()
                        ->rows(3),
                ])
                ->action(function (array $data, MigrationCycleService $migration): void {
                    if (! $this->record instanceof Member) {
                        return;
                    }

                    try {
                        $migration->grantPartialClearance($this->record, (string) $data['notes']);
                    } catch (\InvalidArgumentException $exception) {
                        Notification::make()->title(__('Cannot grant partial clearance'))->body($exception->getMessage())->danger()->send();

                        return;
                    }

                    Notification::make()->title(__('Partial clearance granted'))->success()->send();
                    $this->refreshResolvedRecord();
                }),
            Action::make('clearMigration')
                ->label(__('Clear for active operation'))
                ->icon('heroicon-o-check-badge')
                ->color('success')
                ->requiresConfirmation()
                ->visible(fn (): bool => $this->record instanceof Member
                    && $this->record->migration_status === 'migration_pending')
                ->action(function (MigrationCycleService $migration): void {
                    if (! $this->record instanceof Member) {
                        return;
                    }

                    try {
                        $migration->clearMemberForActiveOperation($this->record);
                    } catch (\InvalidArgumentException $exception) {
                        Notification::make()->title(__('Cannot clear member'))->body($exception->getMessage())->danger()->send();

                        return;
                    }

                    Notification::make()->title(__('Member cleared for active operation'))->success()->send();
                    $this->refreshResolvedRecord();
                }),
        ];
    }
}
