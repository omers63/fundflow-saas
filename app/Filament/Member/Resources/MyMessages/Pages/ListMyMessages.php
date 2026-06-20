<?php

declare(strict_types=1);

namespace App\Filament\Member\Resources\MyMessages\Pages;

use App\Filament\Member\Resources\MyMessages\MyMessageResource;
use App\Filament\Member\Support\ComposeMemberMessageAction;
use Filament\Resources\Pages\ListRecords;

class ListMyMessages extends ListRecords
{
    protected static string $resource = MyMessageResource::class;

    public function mount(): void
    {
        parent::mount();

        if (request()->boolean('compose') && MyMessageResource::resolveAdminRecipient() !== null) {
            $this->defaultAction = 'compose';
        }
    }

    public function getSubheading(): ?string
    {
        return __('Secure messages between you and fund administrators.');
    }

    protected function getHeaderActions(): array
    {
        return [
            ComposeMemberMessageAction::make(),
        ];
    }
}
