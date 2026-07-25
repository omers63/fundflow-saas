<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\MemberCashTransferRequests\Pages;

use App\Filament\Tenant\Resources\MemberCashTransferRequests\MemberCashTransferRequestResource;
use App\Models\Tenant\Member;
use App\Services\MemberCashTransferService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class CreateMemberCashTransferRequest extends CreateRecord
{
    protected static string $resource = MemberCashTransferRequestResource::class;

    protected Width|string|null $maxContentWidth = Width::Full;

    public function getTitle(): string
    {
        return __('New cash transfer');
    }

    protected function handleRecordCreation(array $data): Model
    {
        $from = Member::query()->findOrFail($data['from_member_id']);
        $to = Member::query()->findOrFail($data['to_member_id']);
        $service = app(MemberCashTransferService::class);

        try {
            $request = $service->submit(
                from: $from,
                amount: (float) $data['amount'],
                recipientName: $to->name,
                notes: filled($data['notes'] ?? null) ? (string) $data['notes'] : null,
                toMemberId: (int) $to->id,
                bypassAvailabilityGuard: true,
            );

            // Parent→dependent submits complete instantly; peer transfers still need accept.
            if ($request->status === 'pending') {
                $service->accept(
                    $request->fresh(),
                    auth('tenant')->id(),
                    filled($data['notes'] ?? null) ? (string) $data['notes'] : null,
                    (int) $to->id,
                );
            }

            return $request->fresh();
        } catch (InvalidArgumentException|\RuntimeException $exception) {
            throw ValidationException::withMessages([
                'amount' => $exception->getMessage(),
            ]);
        }
    }

    protected function getCreatedNotification(): ?Notification
    {
        return Notification::make()
            ->title(__('Cash transfer completed'))
            ->body(__('Cash moved between members with master cash mirrors.'))
            ->success();
    }
}
