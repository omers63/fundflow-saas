<?php

declare(strict_types=1);

namespace App\Filament\Member\Resources\MyDependents\Support;

use App\Filament\Support\MoneyDisplay;
use App\Filament\Support\TableRecordActionGroups;
use App\Models\Tenant\DependentAllocationChange;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\DependentAllocationService;
use App\Support\Tenant\CurrentMember;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

final class MyDependentTableActions
{
    /**
     * @return list<Action>
     */
    public static function headerActions(): array
    {
        return [
            Action::make('apply_for_dependent')
                ->label(__('Apply for a Dependent'))
                ->icon('heroicon-o-user-plus')
                ->color('success')
                ->url(fn (): string => route('tenant.membership', ['on_behalf' => 1]))
                ->openUrlInNewTab(),

            Action::make('bulk_update_allocations')
                ->label(__('Update allocations (all)'))
                ->icon('heroicon-o-adjustments-horizontal')
                ->color('primary')
                ->modalHeading(__('Update dependent allocations'))
                ->modalDescription(new HtmlString(
                    '<p class="text-sm text-gray-600 dark:text-gray-400">'.e(__('Changed amounts are applied immediately and administrators are notified automatically.')).'</p>'
                ))
                ->modalWidth('xl')
                ->schema(fn (): array => self::bulkAllocationSchema())
                ->action(function (array $data): void {
                    $parent = CurrentMember::get();
                    if ($parent === null) {
                        Notification::make()->title(__('Member record not found.'))->danger()->send();

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
                }),
        ];
    }

    /**
     * @return list<Action|ActionGroup>
     */
    public static function recordActions(): array
    {
        return TableRecordActionGroups::wrap([
            Action::make('set_allocation')
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
                            ->label(__('Monthly contribution amount'))
                            ->options(Member::contributionAmountOptions())
                            ->required()
                            ->helperText(__('Current allocation: :amount · Cash balance: :cash', [
                                'amount' => MoneyDisplay::format((float) $record->monthly_contribution_amount, $currency),
                                'cash' => MoneyDisplay::format($record->getCashBalance(), $currency),
                            ])),
                        TextInput::make('note')
                            ->label(__('Note / reason (optional)'))
                            ->maxLength(200)
                            ->placeholder(__('e.g. Income change')),
                    ];
                })
                ->action(function (Member $record, array $data): void {
                    $parent = CurrentMember::get();
                    if ($parent === null) {
                        Notification::make()->title(__('Parent member not found.'))->danger()->send();

                        return;
                    }

                    $newAmount = (int) $data['monthly_contribution_amount'];
                    if (! Member::isValidContributionAmount($newAmount)) {
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
                }),

            Action::make('fund_cash')
                ->label(__('Fund cash account'))
                ->icon('heroicon-o-banknotes')
                ->color('success')
                ->schema(function (Member $record): array {
                    $parent = CurrentMember::get();
                    $currency = Setting::get('general', 'currency', 'USD');

                    return [
                        Placeholder::make('balances')
                            ->label(__('Balances'))
                            ->content(__('Your cash: :parent · :name cash: :dependent', [
                                'parent' => number_format($parent?->getCashBalance() ?? 0, 2).' '.$currency,
                                'name' => $record->name,
                                'dependent' => number_format($record->getCashBalance(), 2).' '.$currency,
                            ])),
                        TextInput::make('amount')
                            ->label(__('Amount to transfer'))
                            ->numeric()
                            ->minValue(0.01)
                            ->required()
                            ->suffix($currency),
                        TextInput::make('note')
                            ->label(__('Note (optional)'))
                            ->maxLength(200),
                    ];
                })
                ->action(function (Member $record, array $data): void {
                    $parent = CurrentMember::get();
                    if ($parent === null) {
                        Notification::make()->title(__('Your member record was not found.'))->danger()->send();

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
                                'amount' => number_format((float) $data['amount'], 2).' '.$currency,
                                'name' => $record->name,
                            ]))
                            ->success()
                            ->send();
                    } catch (\Throwable $exception) {
                        Notification::make()
                            ->title(__('Transfer failed'))
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();
                    }
                }),

            Action::make('view_history')
                ->label(__('History'))
                ->icon('heroicon-o-clock')
                ->color('gray')
                ->modalHeading(fn (Member $record): string => __('Allocation history — :name', ['name' => $record->name]))
                ->modalContent(fn (Member $record): HtmlString => self::allocationHistoryContent($record))
                ->modalSubmitAction(false)
                ->modalCancelActionLabel(__('Close')),
        ]);
    }

    /**
     * @return list<Component>
     */
    private static function bulkAllocationSchema(): array
    {
        $parent = CurrentMember::get();
        $dependents = $parent !== null
            ? $parent->dependents()->orderBy('member_number')->get()
            : collect();

        if ($dependents->isEmpty()) {
            return [
                Placeholder::make('none')
                    ->label('')
                    ->content(__('You have no dependents.')),
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
                ->options(Member::contributionAmountOptions())
                ->default($dependent->monthly_contribution_amount)
                ->required()
                ->helperText(__('Current allocation: :alloc · Cash: :cash', [
                    'alloc' => number_format((float) $dependent->monthly_contribution_amount, 0).' '.$currency,
                    'cash' => number_format($dependent->getCashBalance(), 2).' '.$currency,
                ]));
        }

        $fields[] = TextInput::make('note')
            ->label(__('Note / reason (optional)'))
            ->maxLength(200)
            ->placeholder(__('e.g. Annual review adjustment'))
            ->columnSpanFull();

        return $fields;
    }

    private static function allocationHistoryContent(Member $record): HtmlString
    {
        $currency = Setting::get('general', 'currency', 'USD');

        /** @var Collection<int, DependentAllocationChange> $changes */
        $changes = DependentAllocationChange::query()
            ->where('dependent_member_id', $record->id)
            ->with('changedBy')
            ->latest()
            ->limit(30)
            ->get();

        if ($changes->isEmpty()) {
            return new HtmlString('<p class="text-sm text-gray-500 p-4">'.e(__('No allocation changes recorded.')).'</p>');
        }

        $rows = '';
        foreach ($changes as $change) {
            $dir = $change->isIncrease()
                ? '<span class="text-emerald-600 font-bold">↑</span>'
                : '<span class="text-amber-600 font-bold">↓</span>';
            $delta = $change->isIncrease()
                ? '<span class="text-emerald-600">+'.$currency.' '.number_format(abs($change->delta())).'</span>'
                : '<span class="text-amber-600">−'.$currency.' '.number_format(abs($change->delta())).'</span>';
            $by = e($change->changedBy?->name ?? __('System'));
            $note = $change->note ? '<br><span class="text-gray-400 text-xs">'.e($change->note).'</span>' : '';
            $date = $change->created_at->locale(app()->getLocale())->translatedFormat('d M Y H:i');

            $rows .= "
                <tr class=\"border-b border-gray-100 dark:border-gray-700\">
                    <td class=\"py-2 px-3 text-xs text-gray-500\">{$date}</td>
                    <td class=\"py-2 px-3 text-sm\">{$dir} {$currency} {$change->old_amount} → {$currency} {$change->new_amount}</td>
                    <td class=\"py-2 px-3 text-sm\">{$delta}</td>
                    <td class=\"py-2 px-3 text-sm\">{$by}{$note}</td>
                </tr>";
        }

        return new HtmlString('
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-gray-50 dark:bg-gray-800 text-xs uppercase text-gray-500">
                            <th class="py-2 px-3 text-left">'.e(__('Date')).'</th>
                            <th class="py-2 px-3 text-left">'.e(__('Change')).'</th>
                            <th class="py-2 px-3 text-left">'.e(__('Delta')).'</th>
                            <th class="py-2 px-3 text-left">'.e(__('Changed by'))."</th>
                        </tr>
                    </thead>
                    <tbody>{$rows}</tbody>
                </table>
            </div>");
    }
}
