<?php

namespace App\Filament\Tenant\Resources\MasterAccounts\Pages;

use App\Filament\Tenant\Resources\MasterAccounts\MasterAccountResource;
use App\Models\Tenant\Setting;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\TextEntry;
use Filament\Schemas\Schema;

class ViewMasterAccount extends ViewRecord
{
    protected static string $resource = MasterAccountResource::class;

    public function getHeading(): string
    {
        return $this->record->name;
    }

    public function schema(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Account Details'))
                    ->columns(3)
                    ->schema([
                        TextEntry::make('name'),
                        TextEntry::make('type')
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'cash' => 'info',
                                'fund' => 'success',
                                'bank' => 'primary',
                                'expense' => 'danger',
                                'fees' => 'warning',
                                'invest' => 'gray',
                            }),
                        TextEntry::make('balance')
                            ->money(fn (): string => Setting::get('general', 'currency', 'USD')),
                    ]),
            ]);
    }
}
