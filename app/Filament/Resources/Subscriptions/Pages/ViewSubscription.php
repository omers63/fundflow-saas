<?php

namespace App\Filament\Resources\Subscriptions\Pages;

use App\Filament\Resources\Subscriptions\SubscriptionResource;
use Filament\Actions;
use Filament\Forms\Components\Group;
use Filament\Forms\Components\Split;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Illuminate\Database\Eloquent\Model;

class ViewSubscription extends ViewRecord
{
    protected static string $resource = SubscriptionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make()
                ->visible(fn (Model $record) => auth()->user()->hasRole('super_admin') || $record->tenant->central_user_id === auth()->id()),
        ];
    }

    protected function getFormSchema(): array
    {
        return [
            Split::make([
                Group::make([
                    Section::make(__('Subscription Details'))
                        ->schema([
                            TextInput::make('tenant.name')
                                ->label('Tenant')
                                ->disabled(),
                            TextInput::make('plan.name')
                                ->label('Plan')
                                ->disabled(),
                            TextInput::make('status')
                                ->label('Status')
                                ->disabled(),
                            TextInput::make('starts_at')
                                ->label('Starts at')
                                ->disabled(),
                            TextInput::make('ends_at')
                                ->label('Ends at')
                                ->disabled(),
                            TextInput::make('trial_ends_at')
                                ->label('Trial ends at')
                                ->disabled(),
                            TextInput::make('cancels_at')
                                ->label('Cancels at')
                                ->disabled(),
                            TextInput::make('canceled_at')
                                ->label('Canceled at')
                                ->disabled(),
                        ]),
                ]),
                Group::make([
                    Section::make(__('Other Details'))
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
