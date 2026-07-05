<?php

namespace App\Filament\Member\Resources\MyCashOutRequests\Pages;

use App\Filament\Member\Resources\MyCashOutRequests\MyCashOutRequestResource;
use App\Services\MemberCashOutService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class CreateMyCashOutRequest extends CreateRecord
{
    protected static string $resource = MyCashOutRequestResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $member = auth('tenant')->user()->member;

        try {
            return app(MemberCashOutService::class)->submit(
                member: $member,
                amount: (float) $data['amount'],
                notes: $data['notes'] ?? null,
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'amount' => $exception->getMessage(),
            ]);
        }
    }

    protected function getCreatedNotification(): ?Notification
    {
        return Notification::make()
            ->title(__('Cash-out submitted'))
            ->body(__('Your request has been sent to the admin for review.'))
            ->success();
    }
}
