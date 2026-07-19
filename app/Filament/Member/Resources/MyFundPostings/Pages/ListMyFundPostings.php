<?php

namespace App\Filament\Member\Resources\MyFundPostings\Pages;

use App\Filament\Member\Resources\MyFundPostings\MyFundPostingResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMyFundPostings extends ListRecords
{
    protected static string $resource = MyFundPostingResource::class;

    public function getSubheading(): ?string
    {
        return __('Submit deposits and track review status.');
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__('New deposit'))
                ->icon('heroicon-o-plus-circle'),
        ];
    }
}
