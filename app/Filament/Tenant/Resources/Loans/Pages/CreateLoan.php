<?php

namespace App\Filament\Tenant\Resources\Loans\Pages;

use App\Filament\Tenant\Resources\Loans\LoanResource;
use App\Models\Tenant\Member;
use App\Services\LoanService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

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

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $member = Member::findOrFail($data['member_id']);
        $eligibility = app(LoanService::class)->checkEligibility($member);

        if (! $eligibility['eligible']) {
            Notification::make()
                ->title(__('Member not eligible for a loan'))
                ->body(implode(' ', $eligibility['reasons']))
                ->danger()
                ->persistent()
                ->send();

            $this->halt();
        }

        $totalDue = $data['amount'] + ($data['amount'] * $data['interest_rate'] / 100);
        $data['monthly_repayment'] = round($totalDue / $data['term_months'], 2);
        $data['total_repaid'] = 0;
        $data['status'] = 'pending';
        $data['applied_at'] = now();

        return $data;
    }
}
