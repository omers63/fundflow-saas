<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\SmsTransactions\Schemas;

use App\Models\Tenant\Setting;
use App\Models\Tenant\SmsTransaction;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class SmsTransactionInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $currency = Setting::get('general', 'currency', 'USD');

        return $schema->components([
            Section::make(__('Transaction details'))
                ->columns(2)
                ->schema([
                    TextEntry::make('bank_name')
                        ->label(__('Bank'))
                        ->placeholder(__('—')),
                    TextEntry::make('transaction_date')
                        ->label(__('Date'))
                        ->date('d M Y'),
                    TextEntry::make('amount')
                        ->money($currency),
                    TextEntry::make('transaction_type')
                        ->label(__('Type'))
                        ->badge()
                        ->color(fn (?string $state): string => $state === 'credit' ? 'success' : 'danger'),
                    TextEntry::make('reference')
                        ->placeholder(__('—')),
                    TextEntry::make('member.name')
                        ->label(__('Member'))
                        ->placeholder(__('Not matched')),
                    TextEntry::make('posted_at')
                        ->label(__('Posted at'))
                        ->dateTime('d M Y H:i')
                        ->placeholder(__('Not posted')),
                    TextEntry::make('postedBy.name')
                        ->label(__('Posted by'))
                        ->placeholder(__('—')),
                    TextEntry::make('is_duplicate')
                        ->label(__('Duplicate'))
                        ->badge()
                        ->formatStateUsing(fn (bool $state): string => $state ? __('Yes') : __('No'))
                        ->color(fn (bool $state): string => $state ? 'warning' : 'success'),
                ]),

            Section::make(__('Raw SMS'))
                ->schema([
                    TextEntry::make('raw_sms')
                        ->label(__('Original SMS text'))
                        ->columnSpanFull(),
                ]),

            Section::make(__('Raw CSV row'))
                ->schema([
                    KeyValueEntry::make('raw_data')
                        ->label('')
                        ->columnSpanFull(),
                ])
                ->visible(fn (SmsTransaction $record): bool => filled($record->raw_data)),
        ]);
    }
}
