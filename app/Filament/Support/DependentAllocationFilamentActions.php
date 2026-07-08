<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Filament\Tenant\Resources\Members\MemberResource;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\DependentAllocationService;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\HtmlString;
use Livewire\Component;

final class DependentAllocationFilamentActions
{
    /**
     * @return list<Action>
     */
    public static function forRow(Closure $resolveParent): array
    {
        return [
            self::updateAllocation($resolveParent),
            self::fundCash($resolveParent),
            self::allocationHistory(),
        ];
    }

    public static function updateAllAllocationsHeaderAction(Closure $resolveParent): Action
    {
        return Action::make('updateAllDependentAllocations')
            ->label(__('Update allocations (all)'))
            ->icon('heroicon-o-adjustments-horizontal')
            ->color('primary')
            ->modalHeading(__('Update dependent allocations'))
            ->modalDescription(new HtmlString(
                '<p class="text-sm text-gray-600 dark:text-gray-400">'.e(__('Changed amounts are applied immediately and administrators are notified automatically.')).'</p>'
            ))
            ->modalWidth('xl')
            ->schema(fn (): array => self::bulkAllocationSchema($resolveParent))
            ->action(function (array $data, Component $livewire) use ($resolveParent): void {
                $parent = $resolveParent();

                if (! $parent instanceof Member) {
                    Notification::make()->title(__('Parent member not found.'))->danger()->send();

                    return;
                }

                $amounts = $data['amounts'] ?? [];
                $note = is_string($data['note'] ?? null) ? $data['note'] : null;

                if ($amounts === []) {
                    Notification::make()->title(__('No dependents to update.'))->warning()->send();

                    return;
                }

                $user = auth('tenant')->user();
                $results = app(DependentAllocationService::class)->changeMultiple(
                    parent: $parent,
                    updates: $amounts,
                    note: $note,
                    changedBy: $user instanceof User ? $user : null,
                );

                $body = app(DependentAllocationService::class)->buildSummary($results);
                $updated = collect($results)->filter(fn (array $row): bool => $row['change'] !== null)->count();

                Notification::make()
                    ->title($updated > 0 ? __('Allocations updated') : __('No changes applied'))
                    ->body($body)
                    ->color($updated > 0 ? 'success' : 'info')
                    ->send();

                self::refreshHouseholdViews($livewire);
            });
    }

    public static function bulkUpdateAllocations(Closure $resolveParent): BulkAction
    {
        return BulkAction::make('bulkUpdateDependentAllocations')
            ->label(__('Update allocations'))
            ->icon('heroicon-o-adjustments-horizontal')
            ->color('warning')
            ->modalHeading(__('Update dependent allocations'))
            ->modalDescription(new HtmlString(
                '<p class="text-sm text-gray-600 dark:text-gray-400">'.e(__('Changed amounts are recorded in the allocation history.')).'</p>'
            ))
            ->modalWidth('xl')
            ->schema(fn (): array => self::bulkAllocationSchema($resolveParent))
            ->action(function (Collection $records, array $data, Component $livewire) use ($resolveParent): void {
                $parent = $resolveParent();

                if (! $parent instanceof Member) {
                    Notification::make()->title(__('Parent member not found.'))->danger()->send();

                    return;
                }

                $amounts = $data['amounts'] ?? [];
                $note = is_string($data['note'] ?? null) ? $data['note'] : null;

                if ($amounts === []) {
                    Notification::make()->title(__('No dependents to update.'))->warning()->send();

                    return;
                }

                $user = auth('tenant')->user();
                $results = app(DependentAllocationService::class)->changeMultiple(
                    parent: $parent,
                    updates: $amounts,
                    note: $note,
                    changedBy: $user instanceof User ? $user : null,
                );

                $body = app(DependentAllocationService::class)->buildSummary($results);
                $updated = collect($results)->filter(fn (array $row): bool => $row['change'] !== null)->count();

                Notification::make()
                    ->title($updated > 0 ? __('Allocations updated') : __('No changes applied'))
                    ->body($body)
                    ->color($updated > 0 ? 'success' : 'info')
                    ->send();

                self::refreshHouseholdViews($livewire);
            });
    }

    private static function updateAllocation(Closure $resolveParent): Action
    {
        return Action::make('setDependentAllocation')
            ->label(__('Update allocation'))
            ->icon('heroicon-o-adjustments-horizontal')
            ->color('warning')
            ->fillForm(fn (Member $record): array => [
                'monthly_contribution_amount' => $record->monthly_contribution_amount,
                'note' => null,
            ])
            ->schema(function (Member $record): array {
                $currency = Setting::get('general', 'currency', 'USD');

                return [
                    Select::make('monthly_contribution_amount')
                        ->label(__('Monthly allocation amount'))
                        ->options(Member::dependentContributionAmountOptions())
                        ->required()
                        ->helperText(__('Current allocation: :amount · Cash balance: :cash. None (zero allocation) means you will not fund this dependent’s contribution dues; EMI may still be funded if they have loan dues. Member contribution amounts remain 500–3000.', [
                            'amount' => MoneyDisplay::format((float) $record->monthly_contribution_amount, $currency),
                            'cash' => MoneyDisplay::format($record->getCashBalance(), $currency),
                        ])),
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

                $newAmount = (int) $data['monthly_contribution_amount'];

                if (! Member::isValidDependentContributionAmount($newAmount)) {
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
                    );
                } catch (\Throwable $exception) {
                    Notification::make()
                        ->title(__('Could not update allocation'))
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
                    ->title(__('Allocation updated'))
                    ->body(__('Allocation was updated successfully for :name.', ['name' => $record->name]))
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

    /**
     * @return list<\Filament\Forms\Components\Component>
     */
    private static function bulkAllocationSchema(Closure $resolveParent): array
    {
        $parent = $resolveParent();
        $dependents = $parent instanceof Member
            ? $parent->dependents()->orderBy('member_number')->get()
            : collect();

        if ($dependents->isEmpty()) {
            return [
                Placeholder::make('none')
                    ->label('')
                    ->content(__('This household has no dependents.')),
            ];
        }

        $currency = Setting::get('general', 'currency', 'USD');
        $fields = [];

        foreach ($dependents as $dependent) {
            if (! $dependent instanceof Member) {
                continue;
            }

            $fields[] = Select::make("amounts.{$dependent->id}")
                ->label("{$dependent->member_number} — {$dependent->name}")
                ->options(Member::dependentContributionAmountOptions())
                ->default($dependent->monthly_contribution_amount)
                ->required()
                ->helperText(__('Current allocation: :alloc · Cash: :cash. None (zero allocation) means you will not fund this dependent’s contribution dues; EMI may still be funded if they have loan dues. Member contribution amounts remain 500–3000.', [
                    'alloc' => MoneyDisplay::format((float) $dependent->monthly_contribution_amount, $currency, precision: 0) ?? '',
                    'cash' => MoneyDisplay::format($dependent->getCashBalance(), $currency) ?? '',
                ]));
        }

        $fields[] = TextInput::make('note')
            ->label(__('Note / reason (optional)'))
            ->maxLength(200)
            ->placeholder(__('e.g. Annual review adjustment'))
            ->columnSpanFull();

        return $fields;
    }

    private static function refreshHouseholdViews(Component $livewire): void
    {
        MemberResource::dispatchMemberDetailInsightsRefresh($livewire);

        if (method_exists($livewire, 'resetTable')) {
            $livewire->resetTable();
        }
    }
}
