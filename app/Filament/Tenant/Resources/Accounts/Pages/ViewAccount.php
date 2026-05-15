<?php

namespace App\Filament\Tenant\Resources\Accounts\Pages;

use App\Filament\Tenant\Resources\Accounts\AccountResource;
use App\Models\Tenant\Setting;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\TextEntry;
use Filament\Schemas\Schema;

class ViewAccount extends ViewRecord
{
    protected static string $resource = AccountResource::class;

    public function getHeading(): string
    {
        return $this->record->name;
    }

    public function schema(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Account Details'))
                    ->columns(4)
                    ->schema([
                        TextEntry::make('name'),
                        TextEntry::make('member.name')
                            ->label('Member'),
                        TextEntry::make('type')
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'cash' => 'info',
                                'fund' => 'success',
                            }),
                        TextEntry::make('balance')
                            ->money(fn (): string => Setting::get('general', 'currency', 'USD')),
                    ]),
            ]);
    }
}
