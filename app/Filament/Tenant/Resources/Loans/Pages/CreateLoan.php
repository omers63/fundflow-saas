<?php

namespace App\Filament\Tenant\Resources\Loans\Pages;

use App\Filament\Tenant\Resources\Loans\LoanResource;
use App\Filament\Tenant\Resources\Loans\Schemas\LoanForm;
use App\Models\Tenant\Member;
use App\Services\Loans\LoanLifecycleService;
use App\Support\LoanFundExcessDisposition;
use App\Support\LoanFundingStrategy;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Throwable;

class CreateLoan extends CreateRecord
{
    protected static string $resource = LoanResource::class;

    protected string $view = 'filament.tenant.resources.loans.pages.create-loan';

    public function mount(): void
    {
        parent::mount();

        $fill = [
            'funding_strategy' => LoanFundingStrategy::defaultForApplication(),
            'excess_fund_disposition' => LoanFundExcessDisposition::defaultForApplication(),
            'grace_cycles' => 1,
        ];

        $memberId = request()->query('member_id');
        if (filled($memberId)) {
            $fill['member_id'] = $memberId;
        }

        $this->form->fill($fill);
    }

    public function form(Schema $schema): Schema
    {
        return LoanForm::configure($schema, forCreate: true);
    }

    public function getSubheading(): ?string
    {
        return __('Submit a loan application on behalf of a member — borrower, funding split, and eligibility.');
    }

    /**
     * @return array<Action>
     */
    protected function getFormActions(): array
    {
        return [];
    }

    protected function handleRecordCreation(array $data): Model
    {
        $member = Member::findOrFail($data['member_id']);
        $graceCycles = (int) ($data['grace_cycles'] ?? 1);
        $fundingStrategy = count(LoanFundingStrategy::availableOptions()) === 1
            ? LoanFundingStrategy::defaultForApplication()
            : (string) ($data['funding_strategy'] ?? LoanFundingStrategy::defaultForApplication());

        try {
            return app(LoanLifecycleService::class)->applyForLoan(
                $member,
                (float) $data['amount_requested'],
                filled($data['purpose'] ?? null) ? (string) $data['purpose'] : null,
                filled($data['guarantor_member_id'] ?? null) ? (int) $data['guarantor_member_id'] : null,
                (bool) ($data['is_emergency'] ?? false),
                $graceCycles > 0,
                $graceCycles,
                adminOverrideEligibility: (bool) ($data['override_eligibility'] ?? false),
                eligibilityOverrideReason: filled($data['eligibility_override_reason'] ?? null)
                ? (string) $data['eligibility_override_reason']
                : null,
                fundingStrategy: $fundingStrategy,
                cashOutExcessFund: LoanFundExcessDisposition::toCashOutFlag(
                    (string) ($data['excess_fund_disposition'] ?? LoanFundExcessDisposition::defaultForApplication()),
                ),
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
