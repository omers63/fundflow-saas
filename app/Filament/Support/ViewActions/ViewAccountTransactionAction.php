<?php

namespace App\Filament\Support\ViewActions;

use App\Filament\Support\MoneyDisplay;
use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Support\TableToolbar;
use App\Models\Tenant\Setting;
use App\Models\Tenant\Transaction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

final class ViewAccountTransactionAction
{
    public static function make(): ViewAction
    {
        return ViewAction::make()
            ->modalWidth('lg')
            ->modalHeading(fn (Transaction $record): string => filled($record->description)
                ? $record->description
                : __('Transaction #:id', ['id' => $record->id]))
            ->mutateRecordDataUsing(function (array $data, Transaction $record): array {
                $record->loadMissing(['account', 'member', 'reference']);

                $currency = Setting::get('general', 'currency', 'USD');

                return [
                    ...$data,
                    'transacted_at_display' => $record->transacted_at instanceof Carbon
                        ? $record->transacted_at->format('M j, Y g:i A')
                        : (string) $record->transacted_at,
                    'signed_amount_display' => MoneyDisplay::format($record->getSignedAmount(), $currency),
                    'balance_after_display' => MoneyDisplay::format((float) $record->balance_after, $currency),
                    'account_name' => $record->account?->name,
                    'member_tag' => $record->member?->name,
                    'reference_summary' => $record->bankImportSummary() ?? $record->referenceSummary(),
                    'created_at_display' => $record->created_at?->format('M j, Y g:i A'),
                    'updated_at_display' => $record->updated_at?->format('M j, Y g:i A'),
                ];
            })
            ->schema(self::schema());
    }

    /**
     * @return array<int, Section>
     */
    public static function schema(): array
    {
        return [
            Section::make(__('Transaction details'))
                ->columns(2)
                ->schema([
                    TextInput::make('id')
                        ->label(__('Transaction ID')),
                    TextInput::make('transacted_at_display')
                        ->label(__('Date')),
                    TextInput::make('type')
                        ->label(__('Type')),
                    TextInput::make('signed_amount_display')
                        ->label(__('Amount')),
                    TextInput::make('balance_after_display')
                        ->label(__('Balance after')),
                    TextInput::make('account_name')
                        ->label(__('Account'))
                        ->placeholder(__('—')),
                    TextInput::make('member_tag')
                        ->label(__('Member tag'))
                        ->placeholder(__('—'))
                        ->visible(fn (?string $state): bool => filled($state)),
                    Textarea::make('description')
                        ->label(__('Description'))
                        ->placeholder(__('—'))
                        ->rows(3)
                        ->columnSpanFull(),
                    Textarea::make('reference_summary')
                        ->label(__('Reference'))
                        ->placeholder(__('—'))
                        ->rows(2)
                        ->columnSpanFull(),
                    TextInput::make('created_at_display')
                        ->label(__('Created')),
                    TextInput::make('updated_at_display')
                        ->label(__('Updated')),
                ]),
        ];
    }

    public static function configure(Table $table, bool $editable = true): Table
    {
        $actions = $editable
            ? [
                EditAccountTransactionAction::make(),
                SplitAccountTransactionAction::make(),
                ReverseAccountTransactionAction::make(),
                DeleteAccountTransactionAction::make(),
                self::make()
                    ->hidden(fn (): bool => (bool) Auth::guard('tenant')->user()?->is_admin),
            ]
            : [self::make()];

        return TableGrouping::apply(
            $table
                ->recordUrl(fn (): ?string => null)
                ->recordActions(TableRecordActionGroups::wrap($actions)),
            TableGrouping::accountTransactions()
        )
            ->recordAction(function () use ($editable): ?string {
                if (! $editable) {
                    return ViewAction::getDefaultName();
                }

                return Auth::guard('tenant')->user()?->is_admin
                    ? EditAction::getDefaultName()
                    : ViewAction::getDefaultName();
            });
    }

    /**
     * @return array<int, BulkActionGroup>
     */
    public static function tenantToolbarActions(): array
    {
        return [
            BulkActionGroup::make([
                DeleteAccountTransactionsBulkAction::make(),
                TableToolbar::refreshBulkAction(),
            ]),
        ];
    }
}
