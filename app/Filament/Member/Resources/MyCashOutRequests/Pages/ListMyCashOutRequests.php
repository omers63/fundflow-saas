<?php

declare(strict_types=1);

namespace App\Filament\Member\Resources\MyCashOutRequests\Pages;

use App\Filament\Member\Resources\MyCashOutRequests\MyCashOutRequestResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMyCashOutRequests extends ListRecords
{
    protected static string $resource = MyCashOutRequestResource::class;

    public function getSubheading(): ?string
    {
        return __('Request a withdrawal from your cash account to your registered bank account.');
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__('Request cash out'))
                ->icon('heroicon-o-plus-circle'),
        ];
    }
}
