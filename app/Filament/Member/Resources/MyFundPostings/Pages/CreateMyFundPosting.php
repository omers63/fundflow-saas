<?php

namespace App\Filament\Member\Resources\MyFundPostings\Pages;

use App\Filament\Member\Resources\MyFundPostings\MyFundPostingResource;
use App\Services\FundPostingService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateMyFundPosting extends CreateRecord
{
    protected static string $resource = MyFundPostingResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $member = auth('tenant')->user()->member;
        $service = app(FundPostingService::class);

        return $service->submit(
            member: $member,
            amount: (float) $data['amount'],
            postingDate: $data['posting_date'],
            reference: $data['reference'] ?? null,
            attachment: $data['attachment'] ?? null,
            comments: $data['comments'] ?? null,
        );
    }

    protected function getCreatedNotification(): ?Notification
    {
        $record = $this->getRecord();

        if ($record?->status === 'accepted') {
            return Notification::make()
                ->title(__('Deposit accepted'))
                ->body(__('Your deposit was accepted and credited to your cash account.'))
                ->success();
        }

        return Notification::make()
            ->title(__('Deposit submitted'))
            ->body(__('Your request has been sent to the admin for review.'))
            ->success();
    }
}
