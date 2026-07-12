<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Filament\Member\Resources\MyDependents\Pages\ListMyDependents;
use App\Filament\Tenant\Resources\Members\MemberResource;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\DependentAllocationService;
use Closure;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\HtmlString;
use Livewire\Component;
use Livewire\Livewire;

final class DependentAllocationFilamentActions
{
    /**
     * @return list<Action>
     */
    public static function forRow(Closure $resolveParent): array
    {
        return [
            self::manageFunding($resolveParent),
            self::fundCash($resolveParent),
            self::allocationHistory(),
        ];
    }

    private static function manageFunding(Closure $resolveParent): Action
    {
        return Action::make('setDependentAllocation')
            ->label(__('Manage funding'))
            ->icon('heroicon-o-adjustments-horizontal')
            ->color('warning')
            ->fillForm(fn (Member $record): array => [
                'funded_by_parent' => $record->isFundedByParent(),
                'monthly_contribution_amount' => $record->monthly_contribution_amount,
                'note' => null,
            ])
            ->schema(function (Member $record): array {
                $currency = Setting::get('general', 'currency', 'USD');

                return [
                    Toggle::make('funded_by_parent')
                        ->label(__('Funded by parent'))
                        ->helperText(__('When enabled, you set the monthly contribution and the parent covers contribution and EMI dues. When disabled, the dependent manages their own contribution amount and pays their own dues.'))
                        ->default(true)
                        ->live(),
                    Select::make('monthly_contribution_amount')
                        ->label(__('Monthly contribution amount'))
                        ->options(Member::dependentContributionAmountOptions())
                        ->required(fn (Get $get): bool => (bool) $get('funded_by_parent'))
                        ->visible(fn (Get $get): bool => (bool) $get('funded_by_parent'))
                        ->helperText(__('Current amount: :amount · Cash balance: :cash', [
                            'amount' => MoneyDisplay::format((float) $record->monthly_contribution_amount, $currency),
                            'cash' => MoneyDisplay::format($record->getCashBalance(), $currency),
                        ])),
                    Placeholder::make('self_funded_notice')
                        ->label('')
                        ->visible(fn (Get $get): bool => ! (bool) $get('funded_by_parent'))
                        ->content(__('The dependent chooses their own contribution amount in profile settings and is responsible for contribution and EMI payments from their cash account.')),
                    TextInput::make('note')
                        ->label(__('Note / reason (optional)'))
                        ->maxLength(200)
                        ->placeholder(__('e.g. Income change')),
                ];
            })
            ->action(function (Member $record, array $data, Component $livewire) use ($resolveParent): void {
                $parent = $resolveParent();

                if (! $parent instanceof Member) {
                    Notification::make()->title(__('Parent member not found.'))->danger()->send();

                    return;
                }

                $fundedByParent = (bool) ($data['funded_by_parent'] ?? false);
                $newAmount = $fundedByParent
                    ? (int) $data['monthly_contribution_amount']
                    : (int) $record->monthly_contribution_amount;

                if ($fundedByParent && ! Member::isValidDependentContributionAmount($newAmount)) {
                    Notification::make()->title(__('Invalid amount selected.'))->danger()->send();

                    return;
                }

                try {
                    $user = auth('tenant')->user();
                    $change = app(DependentAllocationService::class)->changeAllocation(
                        parent: $parent,
                        dependent: $record,
                        newAmount: $newAmount,
                        note: is_string($data['note'] ?? null) ? $data['note'] : null,
                        changedBy: $user instanceof User ? $user : null,
                        excludeFromHouseholdContributionFunding: ! $fundedByParent,
                    );
                } catch (\Throwable $exception) {
                    Notification::make()
                        ->title(__('Could not update funding'))
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                if ($change === null) {
                    Notification::make()->title(__('No changes detected.'))->info()->send();

                    return;
                }

                Notification::make()
                    ->title(__('Funding updated'))
                    ->body(__('Funding settings were updated for :name.', ['name' => $record->name]))
                    ->success()
                    ->send();

                self::refreshHouseholdViews($livewire);
            });
    }

    private static function fundCash(Closure $resolveParent): Action
    {
        return Action::make('fundDependentCash')
            ->label(__('Fund cash account'))
            ->icon('heroicon-o-banknotes')
            ->color('success')
            ->schema(function (Member $record) use ($resolveParent): array {
                $parent = $resolveParent();
                $currency = Setting::get('general', 'currency', 'USD');

                return [
                    Placeholder::make('balances')
                        ->label(__('Balances'))
                        ->content(__('Parent cash: :parent · :name cash: :dependent', [
                            'parent' => MoneyDisplay::format($parent?->getCashBalance() ?? 0, $currency) ?? '',
                            'name' => $record->name,
                            'dependent' => MoneyDisplay::format($record->getCashBalance(), $currency) ?? '',
                        ])),
                    TextInput::make('amount')
                        ->label(__('Amount to transfer'))
                        ->numeric()
                        ->minValue(0.01)
                        ->required()
                        ->suffix(MoneyDisplay::symbol($currency)),
                    TextInput::make('note')
                        ->label(__('Note (optional)'))
                        ->maxLength(200),
                ];
            })
            ->action(function (Member $record, array $data, Component $livewire) use ($resolveParent): void {
                $parent = $resolveParent();

                if (! $parent instanceof Member) {
                    Notification::make()->title(__('Parent member not found.'))->danger()->send();

                    return;
                }

                try {
                    app(AccountingService::class)->fundDependentCashAccount(
                        parent: $parent,
                        dependent: $record,
                        amount: (float) $data['amount'],
                        note: is_string($data['note'] ?? '') ? $data['note'] : '',
                    );

                    $currency = Setting::get('general', 'currency', 'USD');
                    Notification::make()
                        ->title(__('Transfer successful'))
                        ->body(__(':amount transferred to :name cash account.', [
                            'amount' => MoneyDisplay::format((float) $data['amount'], $currency) ?? '',
                            'name' => $record->name,
                        ]))
                        ->success()
                        ->send();

                    self::refreshHouseholdViews($livewire);
                } catch (\Throwable $exception) {
                    Notification::make()
                        ->title(__('Transfer failed'))
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    private static function allocationHistory(): Action
    {
        return Action::make('dependentAllocationHistory')
            ->label(__('History'))
            ->icon('heroicon-o-clock')
            ->color('gray')
            ->modalHeading(fn (Member $record): string => __('Allocation history — :name', ['name' => $record->name]))
            ->modalContent(fn (Member $record): HtmlString => DependentAllocationHistory::modalContent($record))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('Close'));
    }

    private static function refreshHouseholdViews(Component $livewire): void
    {
        if (method_exists($livewire, 'resetTable')) {
            $livewire->resetTable();
        }

        if (Filament::getCurrentPanel()?->getId() === 'member') {
            self::refreshMemberDependentsInsights($livewire);

            return;
        }

        MemberResource::dispatchMemberDetailInsightsRefresh($livewire);
    }

    private static function refreshMemberDependentsInsights(Component $livewire): void
    {
        $page = self::resolveMemberDependentsListPage($livewire);

        if ($page instanceof ListMyDependents) {
            $page->refreshDependentsInsights();

            return;
        }

        $livewire->dispatch('refresh-member-dependents-insights');
    }

    private static function resolveMemberDependentsListPage(Component $livewire): ?ListMyDependents
    {
        if ($livewire instanceof ListMyDependents) {
            return $livewire;
        }

        $current = Livewire::current();

        if ($current instanceof ListMyDependents) {
            return $current;
        }

        return null;
    }
}
