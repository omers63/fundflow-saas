<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\GatewayPayments\Tables;

use App\Filament\Support\DateColumnRangeFilter;
use App\Filament\Support\MemberTableColumns;
use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableStandards;
use App\Models\Tenant\GatewayPayment;
use App\Models\Tenant\Setting;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

final class GatewayPaymentsTable
{
    public static function configure(Table $table): Table
    {
        return TableGrouping::apply(
            $table
                ->columns([
                    MemberTableColumns::relationNumber(),
                    TextColumn::make('member.name')
                        ->label(__('Member'))
                        ->searchable()
                        ->sortable(),
                    TextColumn::make('purpose')
                        ->badge()
                        ->formatStateUsing(fn (string $state): string => match ($state) {
                            GatewayPayment::PURPOSE_CONTRIBUTION => __('Contribution'),
                            GatewayPayment::PURPOSE_EMI => __('EMI'),
                            GatewayPayment::PURPOSE_FEE => __('Fee'),
                            GatewayPayment::PURPOSE_DEPOSIT => __('Deposit'),
                            GatewayPayment::PURPOSE_ARREARS => __('Arrears'),
                            default => ucfirst($state),
                        }),
                    TextColumn::make('amount')
                        ->money(fn (): string => Setting::get('general', 'currency', 'SAR'))
                        ->sortable(),
                    TextColumn::make('status')
                        ->badge()
                        ->color(fn (string $state): string => match ($state) {
                            GatewayPayment::STATUS_PAID => 'success',
                            GatewayPayment::STATUS_PENDING => 'warning',
                            GatewayPayment::STATUS_FAILED => 'danger',
                            GatewayPayment::STATUS_CANCELLED => 'gray',
                            default => 'gray',
                        })
                        ->formatStateUsing(fn (string $state): string => match ($state) {
                            GatewayPayment::STATUS_PAID => __('Paid'),
                            GatewayPayment::STATUS_PENDING => __('Pending'),
                            GatewayPayment::STATUS_FAILED => __('Failed'),
                            GatewayPayment::STATUS_CANCELLED => __('Cancelled'),
                            default => ucfirst($state),
                        }),
                    TextColumn::make('provider')
                        ->badge()
                        ->formatStateUsing(fn (string $state): string => ucfirst($state)),
                    TextColumn::make('provider_ref')
                        ->label(__('Provider ref'))
                        ->searchable()
                        ->toggleable(),
                    TextColumn::make('posted_at')
                        ->dateTime()
                        ->sortable()
                        ->placeholder(__('—')),
                    TextColumn::make('created_at')
                        ->label(__('Created'))
                        ->dateTime()
                        ->sortable(),
                ])
                ->filters([
                    SelectFilter::make('status')
                        ->options([
                            GatewayPayment::STATUS_PENDING => __('Pending'),
                            GatewayPayment::STATUS_PAID => __('Paid'),
                            GatewayPayment::STATUS_FAILED => __('Failed'),
                            GatewayPayment::STATUS_CANCELLED => __('Cancelled'),
                        ]),
                    SelectFilter::make('provider')
                        ->options([
                            GatewayPayment::PROVIDER_FAKE => __('Fake'),
                            GatewayPayment::PROVIDER_MOYASAR => __('Moyasar'),
                            GatewayPayment::PROVIDER_HYPERPAY => __('HyperPay'),
                        ]),
                    SelectFilter::make('purpose')
                        ->options([
                            GatewayPayment::PURPOSE_CONTRIBUTION => __('Contribution'),
                            GatewayPayment::PURPOSE_EMI => __('EMI'),
                            GatewayPayment::PURPOSE_FEE => __('Fee'),
                            GatewayPayment::PURPOSE_DEPOSIT => __('Deposit'),
                            GatewayPayment::PURPOSE_ARREARS => __('Arrears'),
                        ]),
                    DateColumnRangeFilter::make('created_at', __('Created')),
                    DateColumnRangeFilter::make('posted_at', __('Posted')),
                ])
                ->defaultSort('created_at', 'desc')
                ->toolbarActions(TableStandards::defaultToolbarActions()),
            TableGrouping::members(),
        );
    }
}
