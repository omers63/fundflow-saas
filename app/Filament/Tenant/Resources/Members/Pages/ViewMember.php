<?php

namespace App\Filament\Tenant\Resources\Members\Pages;

use App\Filament\Tenant\Resources\Members\MemberResource;
use App\Models\Tenant\Setting;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\TextEntry;
use Filament\Schemas\Schema;

class ViewMember extends ViewRecord
{
    protected static string $resource = MemberResource::class;

    public function getHeading(): string
    {
        return $this->record->name;
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }

    public function schema(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Member Details'))
                    ->columns(4)
                    ->schema([
                        TextEntry::make('member_number')
                            ->label('Member #'),
                        TextEntry::make('name'),
                        TextEntry::make('email'),
                        TextEntry::make('phone')
                            ->placeholder(__('—')),
                        TextEntry::make('monthly_contribution_amount')
                            ->label('Monthly contribution')
                            ->money(fn (): string => Setting::get('general', 'currency', 'USD')),
                        TextEntry::make('joined_at')
                            ->label('Joined')
                            ->date(),
                        TextEntry::make('status')
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'active' => 'success',
                                'inactive' => 'danger',
                                'suspended' => 'warning',
                                default => 'gray',
                            }),
                        TextEntry::make('parent.name')
                            ->label('Parent member')
                            ->placeholder(__('Independent')),
                    ]),
            ]);
    }
}
