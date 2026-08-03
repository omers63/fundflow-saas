<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\OutboundPayments\Tables;

use App\Filament\Support\ActionModalFailure;
use App\Filament\Support\DateColumnRangeFilter;
use App\Filament\Support\MemberTableColumns;
use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Support\TableToolbar;
use App\Filament\Tenant\Support\TenantPortalViewModal;
use App\Models\Tenant\OutboundPayment;
use App\Models\Tenant\Setting;
use App\Services\OutboundPaymentService;
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

class OutboundPaymentsTable
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
                    ->formatStateUsing(fn (string $state, OutboundPayment $record): string => $record->typeLabel())
                    ->sortable(),
                TextColumn::make('payee_name')
                    ->label(__('Payee'))
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
                    ->tooltip(fn (OutboundPayment $record): string => $record->reason)
                    ->wrap()
                    ->toggleable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state, OutboundPayment $record): string => $record->statusLabel())
                    ->color(fn (string $state): string => match ($state) {
                        OutboundPayment::STATUS_PENDING => 'warning',
                        OutboundPayment::STATUS_COMPLETED => 'success',
                        OutboundPayment::STATUS_CANCELLED => 'gray',
                        default => 'gray',
                    }),
                TextColumn::make('payment_method')
                    ->label(__('Method'))
                    ->placeholder(__('—'))
                    ->formatStateUsing(fn (?string $state, OutboundPayment $record): ?string => $record->paymentMethodLabel())
                    ->toggleable(),
                TextColumn::make('check_number')
                    ->label(__('Check #'))
                    ->placeholder(__('—'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('payment_reference')
                    ->label(__('Reference'))
                    ->placeholder(__('—'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('payee_iban')
                    ->label(__('IBAN'))
                    ->placeholder(__('—'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('paid_at')
                    ->label(__('Paid at'))
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
                    ->options(OutboundPayment::statusLabels())
                    ->default(OutboundPayment::STATUS_PENDING),
                SelectFilter::make('type')
                    ->options(OutboundPayment::typeLabels()),
                DateColumnRangeFilter::make('instruction_date', __('Date')),
            ])
            ->recordActions(TableRecordActionGroups::wrap([
                Action::make('complete')
                    ->label(__('Mark transferred'))
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (OutboundPayment $record): bool => $record->isPending())
                    ->requiresConfirmation()
                    ->modalHeading(__('Mark remittance transferred'))
                    ->modalDescription(__('Record how the money was sent (transfer, wire, or check). Matching the bank statement clear is a separate step in Bank Clearing.'))
                    ->schema([
                        Select::make('payment_method')
                            ->label(__('Payment method'))
                            ->options(OutboundPayment::paymentMethodLabels())
                            ->required()
                            ->live(),
                        TextInput::make('check_number')
                            ->label(__('Check number'))
                            ->maxLength(50)
                            ->visible(fn (Get $get): bool => $get('payment_method') === OutboundPayment::METHOD_CHECK)
                            ->required(fn (Get $get): bool => $get('payment_method') === OutboundPayment::METHOD_CHECK),
                        TextInput::make('payment_reference')
                            ->label(__('Bank / wire reference'))
                            ->maxLength(100)
                            ->helperText(__('Optional transfer or confirmation number from the bank.')),
                        DateTimePicker::make('paid_at')
                            ->label(__('Sent at'))
                            ->default(fn (): string => BusinessDay::now()->toDateTimeString())
                            ->required()
                            ->seconds(false),
                        Textarea::make('completion_notes')
                            ->label(__('Notes'))
                            ->rows(2),
                    ])
                    ->action(function (OutboundPayment $record, array $data, Action $action, OutboundPaymentService $service): void {
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
                            ->title(__('Remittance marked completed'))
                            ->success()
                            ->send();
                    }),
                Action::make('cancel')
                    ->label(__('Cancel'))
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (OutboundPayment $record): bool => $record->isPending())
                    ->requiresConfirmation()
                    ->modalHeading(__('Cancel remittance'))
                    ->modalDescription(__('Use only if this payout should not be sent. Ledger and uncleared bank lines stay as posted.'))
                    ->schema([
                        Textarea::make('completion_notes')
                            ->label(__('Reason'))
                            ->required()
                            ->rows(2),
                    ])
                    ->action(function (OutboundPayment $record, array $data, Action $action, OutboundPaymentService $service): void {
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
                        ->modalHeading(fn (OutboundPayment $record): string => __('Remittance #:id', ['id' => $record->id]))
                        ->modalContent(fn (OutboundPayment $record) => TenantPortalViewModal::content(
                            self::detailSections($record, $currency()),
                        )),
                ),
            ]))
            ->toolbarActions([
                BulkActionGroup::make([
                    TableToolbar::refreshBulkAction(),
                ]),
            ]), TableGrouping::outboundPayments());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function detailSections(OutboundPayment $record, string $currency): array
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
                'title' => __('Payout instructions'),
                'columns' => 2,
                'items' => [
                    ['label' => __('Payee'), 'value' => $record->payee_name],
                    ['label' => __('Amount'), 'value' => number_format((float) $record->amount, 2).' '.$currency],
                    ['label' => __('Reason'), 'value' => $record->reason],
                    ['label' => __('Member #'), 'value' => $record->member?->member_number ?? __('—')],
                    ['label' => __('IBAN'), 'value' => $record->payee_iban ?? __('—')],
                    ['label' => __('Account number'), 'value' => $record->payee_bank_account_number ?? __('—')],
                    ['label' => __('Bank line #'), 'value' => $record->bank_transaction_id ? (string) $record->bank_transaction_id : __('—')],
                    ['label' => __('Created by'), 'value' => $record->creator?->name ?? __('—')],
                ],
            ],
            [
                'title' => __('Transfer completion'),
                'columns' => 2,
                'items' => [
                    ['label' => __('Method'), 'value' => $record->paymentMethodLabel() ?? __('—')],
                    ['label' => __('Check number'), 'value' => $record->check_number ?? __('—')],
                    ['label' => __('Reference'), 'value' => $record->payment_reference ?? __('—')],
                    ['label' => __('Paid at'), 'value' => $record->paid_at?->format('d M Y H:i') ?? __('—')],
                    ['label' => __('Completed by'), 'value' => $record->completer?->name ?? __('—')],
                    ['label' => __('Notes'), 'value' => $record->completion_notes ?? __('—')],
                ],
            ],
        ];
    }
}
