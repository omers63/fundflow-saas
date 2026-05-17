<?php

declare(strict_types=1);

namespace App\Filament\Member\Pages;

use App\Filament\Concerns\TranslatesPageNavigationLabel;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanTier;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Services\Loans\LoanEligibilityService;
use App\Support\LoanSettings;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class LoanCalculator extends Page implements HasForms
{
    use InteractsWithForms;
    use TranslatesPageNavigationLabel;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalculator;

    protected static ?string $navigationLabel = 'Loan calculator';

    protected static ?int $navigationSort = 4;

    protected string $view = 'filament.member.pages.loan-calculator';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'amount' => null,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $currency = Setting::get('general', 'currency', 'USD');

        return $schema
            ->components([
                TextInput::make('amount')
                    ->label(__('Loan amount'))
                    ->numeric()
                    ->minValue(1)
                    ->live(onBlur: true)
                    ->suffix($currency),
            ])
            ->statePath('data');
    }

    /**
     * @return array<string, mixed>
     */
    public function getPreview(): array
    {
        $member = auth('tenant')->user()?->member;
        $amount = (float) ($this->data['amount'] ?? 0);

        if (! $member instanceof Member || $amount <= 0) {
            return [];
        }

        $fundBal = $member->getFundBalance();
        $max = min(
            LoanSettings::maxLoanAmountForMember($fundBal),
            app(LoanEligibilityService::class)->maxLoanAmount($member),
        );
        $tier = LoanTier::forAmount($amount);
        $threshold = LoanSettings::settlementThreshold();
        $minInstall = (float) ($tier?->min_monthly_installment ?? 1000);
        $months = $tier
            ? Loan::computeInstallmentsCount($amount, $fundBal, $minInstall, $threshold)
            : 0;

        return [
            'currency' => Setting::get('general', 'currency', 'USD'),
            'max' => $max,
            'tier' => $tier?->label,
            'months' => $months,
            'installment' => $minInstall,
            'eligible' => $amount <= $max + 0.01 && $tier !== null,
        ];
    }
}
