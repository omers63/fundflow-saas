<?php

namespace App\Filament\Resources\Tenants\Pages;

use App\Filament\Resources\Tenants\TenantResource;
use Filament\Actions;
use Filament\Forms\Components\Group;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Split;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\ViewRecord;

class ViewTenant extends ViewRecord
{
    protected static string $resource = TenantResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make()
                ->visible(fn () => auth()->user()->hasRole('super_admin')),
        ];
    }

    protected function getFormSchema(): array
    {
        return [
            Split::make([
                Group::make([
                    Section::make(__('Details'))
                        ->schema([
                            TextInput::make('id')
                                ->label('ID / Subdomain')
                                ->disabled(),
                            TextInput::make('name')
                                ->label('Business name')
                                ->disabled(),
                            TextInput::make('owner.name')
                                ->label('Owner')
                                ->disabled(),
                            TextInput::make('plan.name')
                                ->label('Plan')
                                ->disabled(),
                            Toggle::make('is_provisioned')
                                ->label('Provisioned')
                                ->disabled(),
                        ]),
                ]),
                Group::make([
                    Section::make(__('Dates'))
                        ->schema([
                            TextInput::make('created_at')
                                ->label('Created at')
                                ->disabled(),
                            TextInput::make('updated_at')
                                ->label('Updated at')
                                ->disabled(),
                        ]),
                ]),
            ]),
        ];
    }
}
