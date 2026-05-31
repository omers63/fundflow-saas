<?php

namespace App\Filament\Member\Resources\MyCashOutRequests\Pages;

use App\Filament\Member\Resources\MyCashOutRequests\MyCashOutRequestResource;
use App\Services\MemberCashOutService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateMyCashOutRequest extends CreateRecord
{
    protected static string $resource = MyCashOutRequestResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $member = auth('tenant')->user()->member;

        return app(MemberCashOutService::class)->submit(
            member: $member,
            amount: (float) $data['amount'],
            notes: $data['notes'] ?? null,
        );
    }

    protected function getCreatedNotification(): ?Notification
    {
        return Notification::make()
            ->title(__('Cash-out submitted'))
            ->body(__('Your request has been sent to the admin for review.'))
            ->success();
    }
}
