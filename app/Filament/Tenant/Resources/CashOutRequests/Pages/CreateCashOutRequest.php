<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\CashOutRequests\Pages;

use App\Filament\Tenant\Resources\CashOutRequests\CashOutRequestResource;
use App\Models\Tenant\Member;
use App\Services\MemberCashOutService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateCashOutRequest extends CreateRecord
{
    protected static string $resource = CashOutRequestResource::class;

    public function mount(): void
    {
        parent::mount();

        $memberId = request()->query('member_id');
        if (filled($memberId)) {
            $this->form->fill(['member_id' => $memberId]);
        }
    }

    public function getTitle(): string
    {
        return __('New cash out');
    }

    protected function handleRecordCreation(array $data): Model
    {
        $member = Member::findOrFail($data['member_id']);
        $service = app(MemberCashOutService::class);

        $request = $service->submit(
            member: $member,
            amount: (float) $data['amount'],
            notes: filled($data['notes'] ?? null) ? (string) $data['notes'] : null,
        );

        $service->accept(
            $request,
            auth('tenant')->id(),
            filled($data['notes'] ?? null) ? (string) $data['notes'] : null,
        );

        return $request->fresh();
    }

    protected function getCreatedNotification(): ?Notification
    {
        return Notification::make()
            ->title(__('Cash out approved'))
            ->body(__('Member and master cash have been debited. Match the bank line when the transfer clears.'))
            ->success();
    }
}
