<?php

namespace App\Filament\Support\ViewActions;

use App\Filament\Support\AccountDetailInsightsRefresh;
use App\Filament\Support\ActionModalFailure;
use App\Filament\Support\MemberLedgerTagSelect;
use App\Models\Tenant\Transaction;
use App\Services\AccountingService;
use App\Support\LedgerSettings;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Illuminate\Support\Facades\Auth;

final class EditAccountTransactionAction
{
    public static function make(): EditAction
    {
        return EditAction::make()
            ->modalWidth('lg')
            ->authorize(fn (): bool => (bool) Auth::guard('tenant')->user()?->is_admin)
            ->visible(fn (): bool => LedgerSettings::showEditDelete())
            ->modalHeading(fn (Transaction $record): string => filled($record->description)
                ? $record->description
                : __('Transaction #:id', ['id' => $record->id]))
            ->modalDescription(__('Changes to amount or type adjust this account balance. Linked source records are not updated here.'))
            ->fillForm(fn (Transaction $record): array => self::formData($record))
            ->schema(self::schema())
            ->using(function (Transaction $record, array $data, Action $action): void {
                ActionModalFailure::attemptThrowable(
                    $action,
                    fn () => app(AccountingService::class)->updateTransaction($record, $data),
                    __('Could not save transaction'),
                );
            })
            ->successNotificationTitle(__('Transaction updated'))
            ->after(fn (Transaction $record) => AccountDetailInsightsRefresh::dispatchLedgerChange((int) $record->account_id));
    }

    /**
     * @return array<string, mixed>
     */
    public static function formData(Transaction $record): array
    {
        $record->loadMissing(['account', 'member', 'reference']);

        return [
            'amount' => $record->amount,
            'type' => $record->type,
            'description' => $record->description,
            'transacted_at' => $record->transacted_at,
            'member_id' => $record->member_id,
            'reference_summary' => $record->bankImportSummary() ?? $record->referenceSummary(),
        ];
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
                    TextInput::make('amount')
                        ->label(__('Amount'))
                        ->numeric()
                        ->required()
                        ->minValue(0.01)
                        ->step(0.01),
                    Select::make('type')
                        ->label(__('Type'))
                        ->options([
                            'credit' => __('Credit'),
                            'debit' => __('Debit'),
                        ])
                        ->required(),
                    DateTimePicker::make('transacted_at')
                        ->label(__('Transaction date & time'))
                        ->required()
                        ->native(false)
                        ->seconds(true),
                    Textarea::make('description')
                        ->label(__('Description'))
                        ->required()
                        ->rows(3)
                        ->columnSpanFull(),
                    MemberLedgerTagSelect::make()
                        ->visible(fn (Transaction $record): bool => (bool) $record->account?->is_master)
                        ->columnSpanFull(),
                    TextInput::make('reference_summary')
                        ->label(__('Reference'))
                        ->disabled()
                        ->dehydrated(false)
                        ->placeholder(__('—'))
                        ->columnSpanFull()
                        ->visible(fn (?string $state): bool => filled($state)),
                ]),
        ];
    }
}
