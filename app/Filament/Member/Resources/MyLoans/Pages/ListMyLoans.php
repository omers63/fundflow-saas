<?php

declare(strict_types=1);

namespace App\Filament\Member\Resources\MyLoans\Pages;

use App\Filament\Member\Pages\ApplyForLoan;
use App\Filament\Member\Pages\LoanCalculatorPage;
use App\Filament\Member\Resources\MyLoans\MyLoanResource;
use App\Filament\Support\MemberLoanFilamentActions;
use App\Filament\Support\RequestLoanEligibilityOverrideAction;
use App\Models\Tenant\Loan;
use App\Services\LoanService;
use App\Services\MemberLoansHubService;
use App\Support\Insights\InsightFormatter;
use App\Support\Tenant\CurrentMember;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\View as SchemaView;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;

class ListMyLoans extends ListRecords
{
    protected static string $resource = MyLoanResource::class;

    #[Url(as: 'hub', except: 'active')]
    public string $hubTab = 'active';

    #[Url(as: 'requestOverride', except: false)]
    public bool $requestOverride = false;

    public function mount(): void
    {
        if (! in_array($this->hubTab, ['active', 'history', 'settle', 'apply'], true)) {
            $this->hubTab = 'active';
        }

        parent::mount();

        if (request()->boolean('requestOverride')) {
            $this->requestOverride = true;
        }

        $this->openEligibilityReviewWhenRequested();
    }

    public function updatedRequestOverride(bool $value): void
    {
        if ($value) {
            $this->openEligibilityReviewWhenRequested();
        }
    }

    protected function openEligibilityReviewWhenRequested(): void
    {
        if (! $this->requestOverride || ! RequestLoanEligibilityOverrideAction::canRequest()) {
            return;
        }

        $this->requestOverride = false;
        $this->mountAction('requestEligibilityOverride');
    }

    public function requestEligibilityOverrideAction(): Action
    {
        return RequestLoanEligibilityOverrideAction::make();
    }

    public function eligibilityReviewPendingAction(): Action
    {
        return RequestLoanEligibilityOverrideAction::pendingReviewAction();
    }

    public function setHubTab(string $tab): void
    {
        if (! in_array($tab, ['active', 'history', 'settle', 'apply'], true)) {
            return;
        }

        $this->hubTab = $tab;
        $this->resetPage();
    }

    public function getSubheading(): ?string
    {
        return __('Track your applications, active loan, and repayment progress.');
    }

    /**
     * @return array<class-string>
     */
    protected function getHeaderWidgets(): array
    {
        return [];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    protected function getHeaderActions(): array
    {
        $member = CurrentMember::get();
        $actions = [
            $this->requestEligibilityOverrideAction(),
            $this->eligibilityReviewPendingAction(),
        ];

        $activeLoan = $this->getSettleLoan();

        if ($activeLoan instanceof Loan && in_array($this->hubTab, ['active', 'settle'], true)) {
            $actions[] = MemberLoanFilamentActions::payOpenPeriodRepayment()->record($activeLoan);
            $actions[] = MemberLoanFilamentActions::earlySettle()->record($activeLoan);
        }

        if ($this->hubTab === 'apply') {
            $actions[] = Action::make('openCalculator')
                ->label(__('Loan calculator'))
                ->icon('heroicon-o-calculator')
                ->url(LoanCalculatorPage::getUrl());
        }

        if (in_array($this->hubTab, ['active', 'apply'], true)) {
            $actions[] = Action::make('applyForLoan')
                ->label(__('Apply for loan'))
                ->icon('heroicon-o-plus')
                ->color('primary')
                ->url(ApplyForLoan::getUrl())
                ->visible(fn (): bool => $member !== null && app(LoanService::class)->checkEligibility($member)['eligible']);
        }

        return $actions;
    }

    protected function getTableQuery(): Builder
    {
        return parent::getTableQuery()->whereRaw('1 = 0');
    }

    public function getSettleLoan(): ?Loan
    {
        $member = CurrentMember::get();

        return $member !== null
            ? app(MemberLoansHubService::class)->settleLoan($member)
            : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function getHubViewData(): array
    {
        $member = CurrentMember::get();
        $hub = app(MemberLoansHubService::class);
        $eligibility = $member !== null ? app(LoanService::class)->checkEligibility($member) : ['eligible' => false];
        $settleLoan = $this->getSettleLoan();

        return [
            'hubTab' => $this->hubTab,
            'currency' => InsightFormatter::currency(),
            'activeLoans' => $member !== null ? $hub->activeLoanCards($member) : [],
            'activeCount' => $member !== null ? $hub->activePipelineCount($member) : 0,
            'historyCount' => $member !== null ? $hub->historyCount($member) : 0,
            'historyLoans' => $member !== null ? $hub->historyLoanCards($member) : [],
            'settleLoan' => $settleLoan !== null ? $hub->loanCard($settleLoan) : null,
            'applyUrl' => ApplyForLoan::getUrl(),
            'calculatorUrl' => LoanCalculatorPage::getUrl(),
            'eligible' => (bool) ($eligibility['eligible'] ?? false),
            'eligibilityReason' => $eligibility['reason'] ?? ($eligibility['reasons'][0] ?? null),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            SchemaView::make('filament.member.resources.my-loans.pages.loans-hub-shell')
                ->viewData(fn (): array => $this->getHubViewData()),
        ]);
    }
}
