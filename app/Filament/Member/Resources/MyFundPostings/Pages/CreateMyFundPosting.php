<?php

namespace App\Filament\Member\Resources\MyFundPostings\Pages;

use App\Filament\Member\Resources\MyFundPostings\MyFundPostingResource;
use App\Models\Tenant\Member;
use App\Models\Tenant\MemberRequest;
use App\Services\FundPostingService;
use App\Services\Tenant\MemberRequestService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateMyFundPosting extends CreateRecord
{
    protected static string $resource = MyFundPostingResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $member = auth('tenant')->user()->member;

        $posting = app(FundPostingService::class)->submit(
            member: $member,
            amount: (float) $data['amount'],
            postingDate: $data['posting_date'],
            reference: $data['reference'] ?? null,
            attachment: $data['attachment'] ?? null,
            comments: $data['comments'] ?? null,
        );

        if (! empty($data['voluntary_topup_enabled']) && ! empty($data['voluntary_topup_amount'])) {
            $targetId = (int) ($data['voluntary_topup_target_member_id'] ?? $member->id);
            $target = $targetId !== (int) $member->id
                ? Member::query()->find($targetId) ?? $member
                : $member;

            try {
                app(MemberRequestService::class)->submit($member, MemberRequest::TYPE_VOLUNTARY_CONTRIBUTION, [
                    'amount' => (float) $data['voluntary_topup_amount'],
                    'note' => $data['voluntary_topup_note'] ?? null,
                    'target_member_id' => $target->id,
                ]);
            } catch (\Throwable) {
                // Deposit already saved; surface failure as a warning notification instead of rolling back.
                Notification::make()
                    ->title(__('Deposit submitted — top-up not saved'))
                    ->body(__('Your deposit was submitted but the voluntary top-up could not be recorded. Please submit it separately from the Contributions page.'))
                    ->warning()
                    ->send();

                return $posting;
            }

            $this->topUpSubmitted = true;
        }

        return $posting;
    }

    protected bool $topUpSubmitted = false;

    protected function getCreatedNotification(): ?Notification
    {
        $record = $this->getRecord();

        $topUpNote = $this->topUpSubmitted
            ? ' '.__('Your voluntary top-up request has also been submitted for admin review.')
            : '';

        if ($record?->status === 'accepted') {
            return Notification::make()
                ->title(__('Deposit accepted'))
                ->body(__('Your deposit was accepted and credited to your cash account.').$topUpNote)
                ->success();
        }

        return Notification::make()
            ->title(__('Deposit submitted'))
            ->body(__('Your request has been sent to the admin for review.').$topUpNote)
            ->success();
    }
}
