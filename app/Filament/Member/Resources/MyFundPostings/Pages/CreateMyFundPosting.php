<?php

namespace App\Filament\Member\Resources\MyFundPostings\Pages;

use App\Filament\Member\Resources\MyFundPostings\MyFundPostingResource;
use App\Filament\Support\MemberContributionFilamentActions;
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

        if (! empty($data['contribution_topup_enabled'])) {
            $eligibleById = collect(MemberContributionFilamentActions::eligibleVoluntaryTopUpTargets($member))
                ->keyBy(fn (Member $candidate): int => (int) $candidate->id);

            $rows = collect($data['contribution_topups'] ?? [])
                ->filter(function (mixed $row, mixed $id) use ($eligibleById): bool {
                    if (! is_array($row) || empty($row['include']) || empty($row['extra'])) {
                        return false;
                    }

                    return $eligibleById->has((int) $id);
                });

            if ($rows->isEmpty()) {
                Notification::make()
                    ->title(__('Deposit submitted — top-up not saved'))
                    ->body(__('Your deposit was submitted but no members with a top-up amount were selected.'))
                    ->warning()
                    ->send();

                return $posting;
            }

            $note = $data['contribution_topup_note'] ?? null;
            $submitted = 0;
            $failed = 0;

            foreach ($rows as $targetId => $row) {
                /** @var Member $target */
                $target = $eligibleById->get((int) $targetId);
                $extra = (float) $row['extra'];
                $amount = round((float) $target->monthly_contribution_amount + $extra, 2);

                try {
                    app(MemberRequestService::class)->submit($member, MemberRequest::TYPE_VOLUNTARY_CONTRIBUTION, [
                        'amount' => $amount,
                        'note' => $note,
                        'target_member_id' => $target->id,
                    ]);
                    $submitted++;
                } catch (\Throwable) {
                    $failed++;
                }
            }

            if ($submitted > 0) {
                $this->topUpSubmitted = true;
            }

            if ($failed > 0) {
                Notification::make()
                    ->title($submitted > 0
                        ? __('Deposit submitted — some top-ups not saved')
                        : __('Deposit submitted — top-up not saved'))
                    ->body(__('Your deposit was submitted but :failed of :total top-up request(s) could not be recorded. Please submit any missing ones separately from the Contributions page.', [
                        'failed' => $failed,
                        'total' => $submitted + $failed,
                    ]))
                    ->warning()
                    ->send();
            }
        }

        return $posting;
    }

    protected bool $topUpSubmitted = false;

    protected function getCreatedNotification(): ?Notification
    {
        $record = $this->getRecord();

        $topUpNote = $this->topUpSubmitted
            ? ' '.__('Your top-up request has also been submitted for admin review.')
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
