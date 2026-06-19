<?php

declare(strict_types=1);

namespace App\Filament\Member\Widgets;

use App\Filament\Member\Resources\MyMessages\MyMessageResource;
use App\Filament\Member\Resources\MyMessages\Tables\MyMessagesTable;
use Filament\Actions\Action;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class MemberMessagesTableWidget extends TableWidget
{
    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    public function getHeading(): ?string
    {
        return __('Inbox');
    }

    protected function getTableQuery(): Builder
    {
        return MyMessageResource::getEloquentQuery();
    }

    public function table(Table $table): Table
    {
        return MyMessagesTable::configure($table)
            ->heading(__('Inbox'))
            ->description(__('Secure messages between you and fund administrators.'))
            ->headerActions([
                Action::make('compose')
                    ->label(__('New message'))
                    ->icon('heroicon-o-pencil-square')
                    ->url(MyMessageResource::getUrl('index').'?compose=1')
                    ->visible(fn (): bool => MyMessageResource::resolveAdminRecipient() !== null),
            ]);
    }
}
