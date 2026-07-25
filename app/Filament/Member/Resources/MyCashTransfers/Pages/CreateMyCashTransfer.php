<?php

declare(strict_types=1);

namespace App\Filament\Member\Resources\MyCashTransfers\Pages;

use App\Filament\Member\Resources\MyCashTransfers\MyCashTransferResource;
use App\Models\Tenant\Member;
use App\Services\MemberCashTransferService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class CreateMyCashTransfer extends CreateRecord
{
    protected static string $resource = MyCashTransferResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $member = auth('tenant')->user()->member;
        $service = app(MemberCashTransferService::class);

        try {
            if (($data['transfer_mode'] ?? 'other') === 'dependent') {
                $dependent = Member::query()->findOrFail((int) $data['to_member_id']);

                return $service->transferToDependent(
                    parent: $member,
                    dependent: $dependent,
                    amount: (float) $data['amount'],
                    notes: $data['notes'] ?? null,
                );
            }

            return $service->submit(
                from: $member,
                amount: (float) $data['amount'],
                recipientName: (string) $data['recipient_name'],
                notes: $data['notes'] ?? null,
            );
        } catch (InvalidArgumentException|\RuntimeException $exception) {
            throw ValidationException::withMessages([
                'amount' => $exception->getMessage(),
            ]);
        }
    }

    protected function getCreatedNotification(): ?Notification
    {
        $record = $this->getRecord();

        if ($record?->status === 'accepted') {
            return Notification::make()
                ->title(__('Cash transfer completed'))
                ->body(__('Cash moved to the dependent immediately.'))
                ->success();
        }

        return Notification::make()
            ->title(__('Cash transfer submitted'))
            ->body(__('Your request has been sent to the admin for review.'))
            ->success();
    }
}
