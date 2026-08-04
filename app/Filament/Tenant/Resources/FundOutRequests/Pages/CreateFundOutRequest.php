<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\FundOutRequests\Pages;

use App\Filament\Support\MemberFilamentActions;
use App\Filament\Tenant\Resources\FundOutRequests\FundOutRequestResource;
use App\Models\Tenant\Member;
use App\Services\MemberFundOutService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Model;

class CreateFundOutRequest extends CreateRecord
{
    protected static string $resource = FundOutRequestResource::class;

    protected Width|string|null $maxContentWidth = Width::Full;

    public function mount(): void
    {
        parent::mount();

        $memberId = request()->query('member_id');
        if (filled($memberId)) {
            $this->form->fill([
                'member_id' => $memberId,
                'fund_out_date' => MemberFilamentActions::businessDayPickerDefault(),
            ]);
        }
    }

    public function getTitle(): string
    {
        return __('New fund out');
    }

    protected function handleRecordCreation(array $data): Model
    {
        $member = Member::findOrFail($data['member_id']);
        $service = app(MemberFundOutService::class);
        $notes = filled($data['notes'] ?? null) ? (string) $data['notes'] : null;
        $transactedAt = MemberFilamentActions::resolveCashOutDate($data['fund_out_date'] ?? null);

        $request = $service->submit(
            member: $member,
            amount: (float) $data['amount'],
            notes: $notes,
        );

        $service->accept(
            $request,
            auth('tenant')->id(),
            $notes,
            $transactedAt,
        );

        return $request->fresh();
    }

    protected function getCreatedNotification(): ?Notification
    {
        return Notification::make()
            ->title(__('Fund out approved'))
            ->body(__('The amount was moved from the member’s fund account to cash (with master mirrors). No bank remittance is created — use cash out if money must leave the bank.'))
            ->success();
    }
}
