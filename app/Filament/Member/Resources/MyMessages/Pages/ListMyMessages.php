<?php

declare(strict_types=1);

namespace App\Filament\Member\Resources\MyMessages\Pages;

use App\Filament\Member\Pages\CommunicationsPage;
use App\Filament\Member\Resources\MyMessages\MyMessageResource;
use Filament\Resources\Pages\ListRecords;

class ListMyMessages extends ListRecords
{
    protected static string $resource = MyMessageResource::class;

    public function mount(): void
    {
        $parameters = ['tab' => 'messages'];

        if (request()->boolean('compose')) {
            $parameters['compose'] = '1';
        }

        $this->redirect(CommunicationsPage::getUrl($parameters), navigate: true);
    }
}
