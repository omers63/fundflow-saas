<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\FundPostings\Pages;

use App\Filament\Tenant\Resources\FundPostings\FundPostingResource;
use App\Models\Tenant\Member;
use App\Services\FundPostingService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateFundPosting extends CreateRecord
{
    protected static string $resource = FundPostingResource::class;

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
        return __('New deposit');
    }

    protected function handleRecordCreation(array $data): Model
    {
        $member = Member::findOrFail($data['member_id']);
        $service = app(FundPostingService::class);

        $posting = $service->submit(
            member: $member,
            amount: (float) $data['amount'],
            postingDate: $data['posting_date'],
            reference: filled($data['reference'] ?? null) ? (string) $data['reference'] : null,
            attachment: filled($data['attachment'] ?? null) ? (string) $data['attachment'] : null,
            comments: filled($data['comments'] ?? null) ? (string) $data['comments'] : null,
        );

        $service->accept(
            $posting,
            auth('tenant')->id(),
            filled($data['comments'] ?? null) ? (string) $data['comments'] : null,
        );

        return $posting->fresh();
    }

    protected function getCreatedNotification(): ?Notification
    {
        return Notification::make()
            ->title(__('Deposit approved'))
            ->body(__('The deposit has been posted to the member cash account.'))
            ->success();
    }
}
