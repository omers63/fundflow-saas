<?php

declare(strict_types=1);

namespace App\Filament\Member\Pages;

use App\Filament\Concerns\TranslatesPageNavigationLabel;
use App\Filament\Member\Support\MemberNavigation;
use App\Filament\Support\MoneyDisplay;
use App\Models\Tenant\LoanTier;
use App\Models\Tenant\Setting;
use App\Services\MemberLoanCalculatorService;
use App\Support\LoanFundingStrategy;
use App\Support\LoanSettings;
use App\Support\Tenant\CurrentMember;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;

class LoanCalculatorPage extends Page
{
    use TranslatesPageNavigationLabel;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalculator;

    protected static ?string $navigationLabel = 'Loan calculator';

    protected static string|\UnitEnum|null $navigationGroup = MemberNavigation::GROUP_LOANS;

    protected static ?int $navigationSort = MemberNavigation::SORT_LOAN_CALCULATOR;

    protected static ?string $slug = 'loan-calculator';

    protected string $view = 'filament.member.pages.loan-calculator';

    public int|float|string|null $loanAmount = null;

    public string $fundingStrategy = LoanFundingStrategy::MEMBER_FUND_TOPUP;

    public static function canAccess(): bool
    {
        return CurrentMember::get() !== null;
    }

    public function getTitle(): string
    {
        return __('Loan calculator');
    }

    public function getSubheading(): ?string
    {
        return __('Estimate monthly installments and repayment split from your fund balance and active loan tiers.');
    }

    /**
     * @return list<array{
     *     tier: LoanTier,
     *     min_installment: float,
     *     installments: int,
     *     member_portion: float,
     *     master_portion: float,
     *     settlement_amt: float,
     *     total_repay: float
     * }>
     */
    #[Computed]
    public function calculations(): array
    {
        $member = CurrentMember::get();
        $amount = (float) ($this->loanAmount ?? 0);

        if ($member === null || $amount <= 0) {
            return [];
        }

        return app(MemberLoanCalculatorService::class)->calculationsForAmount(
            $amount,
            $member,
            $this->fundingStrategy,
        );
    }

    /**
     * @return Collection<int, LoanTier>
     */
    #[Computed]
    public function activeTiers(): Collection
    {
        return app(MemberLoanCalculatorService::class)->activeTiers();
    }

    #[Computed]
    public function settlementPct(): float
    {
        return app(MemberLoanCalculatorService::class)->settlementThresholdPercent();
    }

    #[Computed]
    public function memberFundBalance(): float
    {
        return CurrentMember::get()?->getFundBalance() ?? 0.0;
    }

    #[Computed]
    public function currency(): string
    {
        return Setting::get('general', 'currency', 'USD');
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function fundingStrategyOptions(): array
    {
        return LoanFundingStrategy::options();
    }

    #[Computed]
    public function memberFundingSplitPercent(): float
    {
        return LoanSettings::memberFundingSplitPercent();
    }

    #[Computed]
    public function masterFundingSplitPercent(): float
    {
        return LoanSettings::masterFundingSplitPercent();
    }

    public function formatTierRange(LoanTier $tier): string
    {
        $currency = $this->currency;

        return (MoneyDisplay::format((float) $tier->min_amount, $currency, precision: 0) ?? '—')
            .' – '
            .(MoneyDisplay::format((float) $tier->max_amount, $currency, precision: 0) ?? '—');
    }
}
