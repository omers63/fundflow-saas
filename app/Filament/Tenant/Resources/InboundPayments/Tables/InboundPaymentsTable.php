<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\InboundPayments\Tables;

use App\Filament\Support\ActionModalFailure;
use App\Filament\Support\DateColumnRangeFilter;
use App\Filament\Support\MemberTableColumns;
use App\Filament\Support\MoneyDisplay;
use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Support\TableToolbar;
use App\Filament\Tenant\Support\TenantPortalViewModal;
use App\Models\Tenant\InboundPayment;
use App\Models\Tenant\Setting;
use App\Services\InboundPaymentService;
use App\Support\BusinessDay;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class InboundPaymentsTable
{
    public static function configure(Table $table): Table
    {
        $currency = fn (): string => (string) Setting::get('general', 'currency', 'USD');

        return TableGrouping::apply($table
            ->modifyQueryUsing(fn ($query) => $query->with(['member', 'creator', 'completer', 'bankTransaction']))
            ->defaultSort('instruction_date', 'desc')
            ->columns([
                TextColumn::make('instruction_date')
                    ->label(__('Date'))
                    ->date()
                    ->sortable(),
                TextColumn::make('type')
                    ->label(__('Type'))
                    ->badge()
                    ->formatStateUsing(fn (string $state, InboundPayment $record): string => $record->typeLabel())
                    ->sortable(),
                TextColumn::make('payer_name')
                    ->label(__('Payer'))
                    ->searchable()
                    ->wrap(),
                MemberTableColumns::relationNumber()
                    ->placeholder(__('—'))
                    ->toggleable(),
                TextColumn::make('amount')
                    ->money($currency)
                    ->sortable(),
                TextColumn::make('reason')
                    ->label(__('Reason'))
                    ->limit(40)
                    ->tooltip(fn (InboundPayment $record): string => $record->reason)
                    ->wrap()
                    ->toggleable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state, InboundPayment $record): string => $record->statusLabel())
                    ->color(fn (string $state): string => match ($state) {
                        InboundPayment::STATUS_PENDING => 'warning',
                        InboundPayment::STATUS_COMPLETED => 'success',
                        InboundPayment::STATUS_CANCELLED => 'gray',
                        default => 'gray',
                    }),
                TextColumn::make('payment_method')
                    ->label(__('Method'))
                    ->placeholder(__('—'))
                    ->formatStateUsing(fn (?string $state, InboundPayment $record): ?string => $record->paymentMethodLabel())
                    ->toggleable(),
                TextColumn::make('check_number')
                    ->label(__('Check #'))
                    ->placeholder(__('—'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('payment_reference')
                    ->label(__('Reference'))
                    ->placeholder(__('—'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('payer_iban')
                    ->label(__('IBAN'))
                    ->placeholder(__('—'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('received_at')
                    ->label(__('Received at'))
                    ->dateTime()
                    ->placeholder(__('—'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('creator.name')
                    ->label(__('Created by'))
                    ->placeholder(__('—'))
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(InboundPayment::statusLabels())
                    ->default(InboundPayment::STATUS_PENDING),
                SelectFilter::make('type')
                    ->options(InboundPayment::typeLabels()),
                DateColumnRangeFilter::make('instruction_date', __('Date')),
            ])
            ->recordActions(TableRecordActionGroups::wrap([
                Action::make('complete')
                    ->label(__('Mark received'))
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (InboundPayment $record): bool => $record->isPending())
                    ->requiresConfirmation()
                    ->modalHeading(__('Mark remittance received'))
                    ->modalDescription(__('Record how the money was received (transfer, wire, or check). Matching the bank statement clear is a separate step in Bank Clearing.'))
                    ->schema([
                        Select::make('payment_method')
                            ->label(__('Payment method'))
                            ->options(InboundPayment::paymentMethodLabels())
                            ->required()
                            ->live(),
                        TextInput::make('check_number')
                            ->label(__('Check number'))
                            ->maxLength(50)
                            ->visible(fn (Get $get): bool => $get('payment_method') === InboundPayment::METHOD_CHECK)
                            ->required(fn (Get $get): bool => $get('payment_method') === InboundPayment::METHOD_CHECK),
                        TextInput::make('payment_reference')
                            ->label(__('Bank / wire reference'))
                            ->maxLength(100)
                            ->helperText(__('Optional transfer or confirmation number from the bank.')),
                        DateTimePicker::make('received_at')
                            ->label(__('Received at'))
                            ->default(fn (): string => BusinessDay::now()->toDateTimeString())
                            ->required()
                            ->seconds(false),
                        Textarea::make('completion_notes')
                            ->label(__('Notes'))
                            ->rows(2),
                    ])
                    ->action(function (InboundPayment $record, array $data, Action $action, InboundPaymentService $service): void {
                        if (! ActionModalFailure::attemptThrowable(
                            $action,
                            fn () => $service->markCompleted(
                                $record,
                                $data,
                                auth('tenant')->id(),
                            ),
                            __('Could not complete remittance'),
                        )) {
                            return;
                        }

                        Notification::make()
                            ->title(__('Remittance marked received'))
                            ->success()
                            ->send();
                    }),
                Action::make('cancel')
                    ->label(__('Cancel'))
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (InboundPayment $record): bool => $record->isPending())
                    ->requiresConfirmation()
                    ->modalHeading(__('Cancel remittance'))
                    ->modalDescription(__('Use only if this receipt will not arrive. Ledger and bank lines stay as posted unless you reject the deposit separately.'))
                    ->schema([
                        Textarea::make('completion_notes')
                            ->label(__('Reason'))
                            ->required()
                            ->rows(2),
                    ])
                    ->action(function (InboundPayment $record, array $data, Action $action, InboundPaymentService $service): void {
                        if (! ActionModalFailure::attemptThrowable(
                            $action,
                            fn () => $service->cancel(
                                $record,
                                auth('tenant')->id(),
                                $data['completion_notes'] ?? null,
                            ),
                            __('Could not cancel remittance'),
                        )) {
                            return;
                        }

                        Notification::make()
                            ->title(__('Remittance cancelled'))
                            ->send();
                    }),
                TenantPortalViewModal::apply(
                    ViewAction::make()
                        ->modalHeading(fn (InboundPayment $record): string => __('Inbound remittance #:id', ['id' => $record->id]))
                        ->modalContent(fn (InboundPayment $record) => TenantPortalViewModal::content(
                            self::detailSections($record, $currency()),
                        )),
                ),
            ]))
            ->toolbarActions([
                BulkActionGroup::make([
                    TableToolbar::refreshBulkAction(),
                ]),
            ]), TableGrouping::inboundPayments());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function detailSections(InboundPayment $record, string $currency): array
    {
        return [
            [
                'hero' => [
                    'label' => $record->typeLabel(),
                    'subtitle' => $record->instruction_date?->format('d M Y') ?? __('—'),
                    'chip' => $record->statusLabel(),
                    'chipVariant' => $record->isPending() ? 'amber' : ($record->isCompleted() ? 'green' : 'gray'),
                ],
            ],
            [
                'title' => __('Receipt instructions'),
                'columns' => 2,
                'items' => [
                    ['label' => __('Payer'), 'value' => $record->payer_name],
                    ['label' => __('Amount'), 'value' => MoneyDisplay::format((float) $record->amount, $currency) ?? '—'],
                    ['label' => __('Reason'), 'value' => $record->reason],
                    ['label' => __('Member #'), 'value' => $record->member?->member_number ?? __('—')],
                    ['label' => __('IBAN'), 'value' => $record->payer_iban ?? __('—')],
                    ['label' => __('Account number'), 'value' => $record->payer_bank_account_number ?? __('—')],
                    ['label' => __('Bank line #'), 'value' => $record->bank_transaction_id ? (string) $record->bank_transaction_id : __('—')],
                    ['label' => __('Created by'), 'value' => $record->creator?->name ?? __('—')],
                ],
            ],
            [
                'title' => __('Receipt confirmation'),
                'columns' => 2,
                'items' => [
                    ['label' => __('Method'), 'value' => $record->paymentMethodLabel() ?? __('—')],
                    ['label' => __('Check number'), 'value' => $record->check_number ?? __('—')],
                    ['label' => __('Reference'), 'value' => $record->payment_reference ?? __('—')],
                    ['label' => __('Received at'), 'value' => $record->received_at?->format('d M Y H:i') ?? __('—')],
                    ['label' => __('Completed by'), 'value' => $record->completer?->name ?? __('—')],
                    ['label' => __('Notes'), 'value' => $record->completion_notes ?? __('—')],
                ],
            ],
        ];
    }
}
