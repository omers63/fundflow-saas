<?php

namespace App\Filament\Tenant\Resources\Loans\Pages;

use App\Filament\Tenant\Resources\Loans\LoanResource;
use App\Models\Tenant\Member;
use App\Services\Loans\LoanLifecycleService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Throwable;

class CreateLoan extends CreateRecord
{
    protected static string $resource = LoanResource::class;

    public function mount(): void
    {
        parent::mount();

        $memberId = request()->query('member_id');
        if (filled($memberId)) {
            $this->form->fill(['member_id' => $memberId]);
        }
    }

    protected function handleRecordCreation(array $data): Model
    {
        $member = Member::findOrFail($data['member_id']);

        try {
            return app(LoanLifecycleService::class)->applyForLoan(
                $member,
                (float) $data['amount_requested'],
                filled($data['purpose'] ?? null) ? (string) $data['purpose'] : null,
                filled($data['guarantor_member_id'] ?? null) ? (int) $data['guarantor_member_id'] : null,
                (bool) ($data['is_emergency'] ?? false),
                (bool) ($data['has_grace_cycle'] ?? true),
                adminOverrideEligibility: (bool) ($data['override_eligibility'] ?? false),
                eligibilityOverrideReason: filled($data['eligibility_override_reason'] ?? null)
                ? (string) $data['eligibility_override_reason']
                : null,
            );
        } catch (Throwable $exception) {
            Notification::make()
                ->title(__('Could not create loan application'))
                ->body($exception->getMessage())
                ->danger()
                ->persistent()
                ->send();

            throw new Halt;
        }
    }
}
