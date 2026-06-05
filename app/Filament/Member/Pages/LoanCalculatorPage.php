<?php

declare(strict_types=1);

namespace App\Filament\Member\Pages;

use App\Filament\Concerns\TranslatesPageNavigationLabel;
use App\Filament\Member\Support\MemberNavigation;
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

class LoanCalculatorPage extends Page
{
    use TranslatesPageNavigationLabel;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalculator;

    protected static ?string $navigationLabel = 'Loan calculator';

    protected static string|\UnitEnum|null $navigationGroup = MemberNavigation::GROUP_LOANS;

    protected static ?int $navigationSort = MemberNavigation::SORT_LOAN_CALCULATOR;

    protected static ?string $slug = 'loan-calculator';

    protected string $view = 'filament.member.pages.loan-calculator';

    public float $loanAmount = 0;

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
    public function getCalculationsProperty(): array
    {
        $member = CurrentMember::get();

        if ($member === null || $this->loanAmount <= 0) {
            return [];
        }

        return app(MemberLoanCalculatorService::class)->calculationsForAmount(
            $this->loanAmount,
            $member,
            $this->fundingStrategy,
        );
    }

    /**
     * @return Collection<int, LoanTier>
     */
    public function getActiveTiersProperty(): Collection
    {
        return app(MemberLoanCalculatorService::class)->activeTiers();
    }

    public function getSettlementPctProperty(): float
    {
        return app(MemberLoanCalculatorService::class)->settlementThresholdPercent();
    }

    public function getMemberFundBalanceProperty(): float
    {
        return CurrentMember::get()?->getFundBalance() ?? 0.0;
    }

    public function getCurrencyProperty(): string
    {
        return Setting::get('general', 'currency', 'USD');
    }

    /**
     * @return array<string, string>
     */
    public function getFundingStrategyOptionsProperty(): array
    {
        return LoanFundingStrategy::options();
    }

    public function getMemberFundingSplitPercentProperty(): float
    {
        return LoanSettings::memberFundingSplitPercent();
    }

    public function getMasterFundingSplitPercentProperty(): float
    {
        return LoanSettings::masterFundingSplitPercent();
    }

    public function formatTierRange(LoanTier $tier): string
    {
        $currency = $this->currency;

        return number_format((float) $tier->min_amount, 0).' – '.number_format((float) $tier->max_amount, 0).' '.$currency;
    }
}
