<?php

declare(strict_types=1);

namespace App\Filament\Member\Pages;

use App\Filament\Concerns\TranslatesPageNavigationLabel;
use App\Filament\Member\Support\MemberNavigation;
use App\Filament\Pages\Page;
use App\Filament\Support\MoneyDisplay;
use App\Models\Tenant\LoanTier;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Services\Loans\LoanEligibilityService;
use App\Services\MemberLoanCalculatorService;
use App\Services\MemberLoanLifecycleSimulator;
use App\Support\BusinessDay;
use App\Support\ContributionAmountSettings;
use App\Support\LoanExcessFundSettlementOption;
use App\Support\LoanFundExcessDisposition;
use App\Support\LoanFundingStrategy;
use App\Support\LoanSettings;
use App\Support\Tenant\CurrentMember;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Throwable;

class LoanCalculatorPage extends Page
{
    use TranslatesPageNavigationLabel;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalculator;

    protected static ?string $navigationLabel = 'Loan calculator';

    protected static string|\UnitEnum|null $navigationGroup = MemberNavigation::GROUP_LOANS;

    protected static ?int $navigationSort = MemberNavigation::SORT_LOAN_CALCULATOR;

    protected static ?string $slug = 'loan-calculator';

    protected string $view = 'filament.member.pages.loan-calculator';

    protected Width|string|null $maxContentWidth = Width::Full;

    public const MODE_ESTIMATE = 'estimate';

    public const MODE_SIMULATE = 'simulate';

    public int|float|string|null $loanAmount = null;

    public string $fundingStrategy = '';

    public string $excessFundDisposition = LoanFundExcessDisposition::KEEP_IN_FUND;

    public string $excessFundSettlementOption = LoanExcessFundSettlementOption::KEEP_IN_FUND;

    public int $graceCycles = 0;

    public string $startDate = '';

    public int $projectedContributionAmount = 0;

    public string $calculatorMode = self::MODE_ESTIMATE;

    public int $simulateTierIndex = 0;

    /** @var array<string, mixed>|null */
    public ?array $simulation = null;

    public float|string|null $simulationPaymentAmount = null;

    public int $simulationContributionAmount = 0;

    public function mount(): void
    {
        $this->fundingStrategy = LoanFundingStrategy::defaultForApplication();
        $this->excessFundDisposition = LoanFundExcessDisposition::defaultForApplication();
        $this->excessFundSettlementOption = LoanExcessFundSettlementOption::defaultForApplication();
        $this->graceCycles = LoanSettings::defaultApplicationGraceCycles();
        $this->startDate = BusinessDay::today()->toDateString();
        $this->projectedContributionAmount = $this->normalizeProjectedContribution(
            CurrentMember::get()?->monthly_contribution_amount,
        );
        $this->simulationContributionAmount = $this->projectedContributionAmount;
    }

    public function updatedFundingStrategy(string $value): void
    {
        if (! LoanFundingStrategy::isAvailableForApplication($value)) {
            $this->fundingStrategy = LoanFundingStrategy::defaultForApplication();
        }

        $this->resetSimulation();
    }

    public function updatedGraceCycles(mixed $value): void
    {
        $this->graceCycles = LoanSettings::clampGraceCycles((int) $value);
        $this->resetSimulation();
    }

    public function updatedProjectedContributionAmount(mixed $value): void
    {
        $this->projectedContributionAmount = $this->normalizeProjectedContribution($value);
        $this->resetSimulation();
    }

    public function updatedLoanAmount(mixed $value): void
    {
        $this->resetSimulation();
    }

    public function updatedExcessFundSettlementOption(mixed $value): void
    {
        $this->resetSimulation();
    }

    public function updatedExcessFundDisposition(mixed $value): void
    {
        $this->excessFundDisposition = LoanFundExcessDisposition::normalize(
            is_string($value) ? $value : null,
        );
        $this->resetSimulation();
    }

    public function calculate(): void
    {
        unset($this->calculations);
        $this->resetSimulation();
    }

    public function setCalculatorMode(string $mode): void
    {
        if (! in_array($mode, [self::MODE_ESTIMATE, self::MODE_SIMULATE], true)) {
            return;
        }

        $this->calculatorMode = $mode;

        // Keep an existing simulation when toggling back from Estimate.
        if ($mode === self::MODE_SIMULATE && ! is_array($this->simulation)) {
            $this->startSimulationFromEstimate();
        }
    }

    public function startSimulationFromEstimate(): void
    {
        $calcs = $this->calculations;

        if ($calcs === []) {
            $this->simulation = null;
            Notification::make()
                ->title(__('Calculate a loan estimate first'))
                ->warning()
                ->send();

            $this->calculatorMode = self::MODE_ESTIMATE;

            return;
        }

        $index = max(0, min($this->simulateTierIndex, count($calcs) - 1));
        $this->simulateTierIndex = $index;
        $calc = $calcs[$index];
        $loanAmount = (float) ($this->loanAmount ?? 0);

        $this->simulation = app(MemberLoanLifecycleSimulator::class)->startFromEstimate(
            $calc,
            $loanAmount,
            $this->startDate,
            $this->excessFundDisposition,
        );
        $this->simulationPaymentAmount = (float) ($calc['min_installment'] ?? 0);
        $this->simulationContributionAmount = $this->normalizeProjectedContribution(
            $this->simulationContributionAmount ?: $this->projectedContributionAmount,
        );
    }

    public function applySimulationRegularPayment(): void
    {
        if (! is_array($this->simulation)) {
            return;
        }

        try {
            $this->simulation = app(MemberLoanLifecycleSimulator::class)->applyRegularPayment(
                $this->simulation,
                (float) ($this->simulation['min_installment'] ?? 0),
            );
        } catch (Throwable $e) {
            Notification::make()
                ->title($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function applySimulationPartialEarlySettlement(): void
    {
        if (! is_array($this->simulation)) {
            return;
        }

        try {
            // Mid-life simulator partials always roll up. Estimate roll-up/skip only shapes
            // the disbursement snapshot (excess fund at start), not later lump payments.
            $this->simulation = app(MemberLoanLifecycleSimulator::class)->applyPartialEarlySettlement(
                $this->simulation,
                (float) ($this->simulationPaymentAmount ?? 0),
                MemberLoanLifecycleSimulator::PARTIAL_ROLL_UP,
            );
        } catch (Throwable $e) {
            Notification::make()
                ->title($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function applySimulationFullEarlySettlement(): void
    {
        if (! is_array($this->simulation)) {
            return;
        }

        try {
            $this->simulation = app(MemberLoanLifecycleSimulator::class)->applyFullEarlySettlement(
                $this->simulation,
            );
        } catch (Throwable $e) {
            Notification::make()
                ->title($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function applySimulationContribution(): void
    {
        if (! is_array($this->simulation)) {
            return;
        }

        try {
            $this->simulation = app(MemberLoanLifecycleSimulator::class)->applyContribution(
                $this->simulation,
                (float) $this->simulationContributionAmount,
            );
        } catch (Throwable $e) {
            Notification::make()
                ->title($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function updatedSimulateTierIndex(mixed $value): void
    {
        $this->simulateTierIndex = max(0, (int) $value);

        if ($this->calculatorMode === self::MODE_SIMULATE) {
            $this->startSimulationFromEstimate();
        }
    }

    public function resetSimulation(): void
    {
        $this->simulation = null;

        if ($this->calculatorMode === self::MODE_SIMULATE) {
            $this->calculatorMode = self::MODE_ESTIMATE;
        }
    }

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
        return __('See whether you can apply, then estimate how a loan would be funded, repaid, and what fund balance you would need afterwards.');
    }

    /**
     * @return list<array{
     *     tier: LoanTier,
     *     min_installment: float,
     *     installments: int,
     *     member_portion: float,
     *     master_portion: float,
     *     settlement_amt: float,
     *     total_repay: float,
     *     eligibility_amt: float,
     *     eligibility_base: float,
     *     excess_fund: float,
     *     early_settlement_amount: float,
     *     installments_covered: int,
     *     remaining_payment_months: int|null,
     *     duration_months: int,
     *     schedule: array<string, mixed>
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
            $this->excessFundSettlementOption,
            $this->graceCycles,
            $this->startDate,
            $this->projectedContributionAmount,
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
    public function eligibilityPct(): float
    {
        return app(MemberLoanCalculatorService::class)->eligibilityThresholdPercent();
    }

    #[Computed]
    public function memberFundBalance(): float
    {
        return CurrentMember::get()?->getFundBalance() ?? 0.0;
    }

    /**
     * @return array{
     *     eligible: bool,
     *     reason: string,
     *     fund_balance: float,
     *     max_loan_amount: float,
     *     min_fund_balance: float,
     *     eligible_from: string
     * }
     */
    #[Computed]
    public function eligibility(): array
    {
        $member = CurrentMember::get();

        if ($member === null) {
            return [
                'eligible' => false,
                'reason' => '',
                'fund_balance' => 0.0,
                'max_loan_amount' => 0.0,
                'min_fund_balance' => LoanSettings::minFundBalance(),
                'eligible_from' => '—',
            ];
        }

        $member->loadMissing('fundAccount');

        return app(LoanEligibilityService::class)->context($member);
    }

    #[Computed]
    public function fundAccountUrl(): string
    {
        return FundAccountPage::getUrl();
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
        return LoanFundingStrategy::availableOptions();
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function excessDispositionOptions(): array
    {
        return LoanFundExcessDisposition::availableOptions();
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function settlementOptionOptions(): array
    {
        return LoanExcessFundSettlementOption::options();
    }

    #[Computed]
    public function showsExcessDisposition(): bool
    {
        return LoanFundingStrategy::normalize($this->fundingStrategy) === LoanFundingStrategy::SPLIT_PERCENTAGE
            && count($this->excessDispositionOptions) > 1;
    }

    #[Computed]
    public function showsSettlementOptions(): bool
    {
        return LoanFundingStrategy::normalize($this->fundingStrategy) === LoanFundingStrategy::SPLIT_WITH_EARLY_SETTLEMENT;
    }

    #[Computed]
    public function usesConfiguredSplit(): bool
    {
        return LoanFundingStrategy::usesConfiguredSplit($this->fundingStrategy);
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

    /**
     * @return array<int, string>
     */
    #[Computed]
    public function graceCycleOptions(): array
    {
        return LoanSettings::graceCycleSelectOptions();
    }

    /**
     * @return array<int, string>
     */
    #[Computed]
    public function projectedContributionOptions(): array
    {
        $options = Member::contributionAmountOptions();
        $selected = (int) $this->projectedContributionAmount;

        if ($selected > 0 && ! isset($options[$selected])) {
            $options[$selected] = MoneyDisplay::format($selected, $this->currency, precision: 0) ?? (string) $selected;
            ksort($options, SORT_NUMERIC);
        }

        return $options;
    }

    #[Computed]
    public function currentCycleLabel(): string
    {
        return app(MemberLoanCalculatorService::class)->cycleLabelForDate($this->startDate);
    }

    /**
     * @return array{
     *     current_fund: float,
     *     contribution_amount: float,
     *     cycles_added: int,
     *     projected_fund: float,
     *     start_cycle_month: int,
     *     start_cycle_year: int,
     *     start_cycle_label: string,
     *     start_cycle_paid: bool
     * }
     */
    #[Computed]
    public function projection(): array
    {
        $member = CurrentMember::get();

        if ($member === null) {
            return [
                'current_fund' => 0.0,
                'contribution_amount' => 0.0,
                'cycles_added' => 0,
                'projected_fund' => 0.0,
                'start_cycle_month' => 0,
                'start_cycle_year' => 0,
                'start_cycle_label' => '—',
                'start_cycle_paid' => false,
            ];
        }

        return app(MemberLoanCalculatorService::class)->fundProjection(
            $member,
            $this->startDate,
            $this->projectedContributionAmount,
        );
    }

    public function formatTierRange(LoanTier $tier): string
    {
        $currency = $this->currency;

        return (MoneyDisplay::format((float) $tier->min_amount, $currency, precision: 0) ?? '—')
            .' – '
            .(MoneyDisplay::format((float) $tier->max_amount, $currency, precision: 0) ?? '—');
    }

    private function normalizeProjectedContribution(mixed $value): int
    {
        $amount = (int) $value;

        if (Member::isValidContributionAmount($amount)) {
            return $amount;
        }

        $current = (int) (CurrentMember::get()?->monthly_contribution_amount ?? 0);

        if (Member::isValidContributionAmount($current)) {
            return $current;
        }

        return ContributionAmountSettings::minAmount();
    }
}
