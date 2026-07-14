<?php

declare(strict_types=1);

namespace App\Filament\Member\Resources\MyLoans\Pages;

use App\Filament\Member\Pages\ApplyForLoan;
use App\Filament\Member\Pages\LoanCalculatorPage;
use App\Filament\Member\Resources\MyLoans\MyLoanResource;
use App\Filament\Support\MemberLoanFilamentActions;
use App\Filament\Support\RequestLoanEligibilityOverrideAction;
use App\Models\Tenant\Loan;
use App\Services\Loans\LoanRepaymentService;
use App\Services\LoanService;
use App\Services\MemberLoansHubService;
use App\Support\Insights\InsightFormatter;
use App\Support\Tenant\CurrentMember;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\View as SchemaView;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;

class ListMyLoans extends ListRecords
{
    protected static string $resource = MyLoanResource::class;

    #[Url(as: 'hub', except: 'active')]
    public string $hubTab = 'active';

    #[Url(as: 'requestOverride', except: false)]
    public bool $requestOverride = false;

    #[Url(as: 'openEarlySettle', except: false)]
    public bool $openEarlySettleModal = false;

    public ?int $earlySettleLoanId = null;

    public function mount(): void
    {
        if ($this->hubTab === 'settle') {
            $this->hubTab = 'active';
            $this->openEarlySettleModal = true;
        }

        if (!in_array($this->hubTab, ['active', 'history', 'apply'], true)) {
            $this->hubTab = 'active';
        }

        parent::mount();

        if (request()->boolean('requestOverride')) {
            $this->requestOverride = true;
            $this->hubTab = 'apply';
        }

        if (request()->boolean('openEarlySettle')) {
            $this->openEarlySettleModal = true;
            $this->hubTab = 'active';
        }

        $this->openEligibilityReviewWhenRequested();
        $this->openEarlySettlementWhenRequested();
        $this->refreshHeaderActions();
    }

    public function updatedRequestOverride(bool $value): void
    {
        if ($value) {
            $this->hubTab = 'apply';
            $this->openEligibilityReviewWhenRequested();
        }
    }

    public function updatedOpenEarlySettleModal(bool $value): void
    {
        if ($value) {
            $this->hubTab = 'active';
            $this->openEarlySettlementWhenRequested();
        }
    }

    public function openEarlySettlement(?int $loanId = null): void
    {
        $loan = $this->resolveEarlySettleLoan($loanId);

        if (!$loan instanceof Loan) {
            Notification::make()
                ->title(__('No active loan'))
                ->body(__('You do not have an active loan to settle right now.'))
                ->warning()
                ->send();

            return;
        }

        $this->earlySettleLoanId = $loan->id;
        unset($this->cachedActions['earlySettle']);
        $this->cachedMountedActions = null;

        $this->mountAction('earlySettle');

        if ($this->getMountedAction() === null) {
            Notification::make()
                ->title(__('Early settlement unavailable'))
                ->body(__('We could not open the settlement form. Refresh the page and try again.'))
                ->warning()
                ->send();
        }
    }

    protected function resolveEarlySettleLoan(?int $loanId = null): ?Loan
    {
        $member = CurrentMember::get();

        if ($member === null) {
            return null;
        }

        if ($loanId !== null) {
            return Loan::query()
                ->where('member_id', $member->id)
                ->whereKey($loanId)
                ->where('status', 'active')
                ->first();
        }

        return $this->getSettleLoan();
    }

    protected function openEligibilityReviewWhenRequested(): void
    {
        if (! $this->requestOverride || ! RequestLoanEligibilityOverrideAction::canRequest()) {
            return;
        }

        $this->requestOverride = false;
        $this->hubTab = 'apply';
        $this->mountAction('requestEligibilityOverride');
    }

    protected function openEarlySettlementWhenRequested(): void
    {
        if (!$this->openEarlySettleModal) {
            return;
        }

        $this->openEarlySettleModal = false;
        $this->hubTab = 'active';
        $this->openEarlySettlement();
    }

    public function requestEligibilityOverrideAction(): Action
    {
        return RequestLoanEligibilityOverrideAction::make()
            ->visible(
                fn(): bool => $this->hubTab === 'apply'
                && RequestLoanEligibilityOverrideAction::canRequest(),
            );
    }

    public function eligibilityReviewPendingAction(): Action
    {
        return RequestLoanEligibilityOverrideAction::pendingReviewAction()
            ->visible(
                fn(): bool => $this->hubTab === 'apply'
                && RequestLoanEligibilityOverrideAction::hasPendingRequest(),
            );
    }

    public function payOpenPeriodRepaymentAction(): Action
    {
        $loan = $this->getSettleLoan();
        $member = CurrentMember::get();

        return MemberLoanFilamentActions::payOpenPeriodRepayment()
            ->record($loan)
            ->visible(
                $this->hubTab === 'active'
                && $loan instanceof Loan
                && $member !== null
                && app(LoanRepaymentService::class)->shouldOfferOpenPeriodRepayment($member),
            );
    }

    public function earlySettleAction(): Action
    {
        return MemberLoanFilamentActions::earlySettle()
            ->record(fn(): ?Loan => $this->resolveEarlySettleLoan($this->earlySettleLoanId))
            ->hidden(fn(): bool => $this->hubTab !== 'active');
    }

    public function setHubTab(string $tab): void
    {
        if ($tab === 'settle') {
            $tab = 'active';
        }

        if (!in_array($tab, ['active', 'history', 'apply'], true)) {
            return;
        }

        $this->hubTab = $tab;
        $this->resetPage();
        $this->refreshHeaderActions();
    }

    protected function refreshHeaderActions(): void
    {
        foreach ($this->cachedHeaderActions as $previous) {
            if ($previous instanceof Action) {
                unset($this->cachedActions[$previous->getName()]);
            }

            if ($previous instanceof ActionGroup) {
                foreach ($previous->getFlatActions() as $flatAction) {
                    unset($this->cachedActions[$flatAction->getName()]);
                }
            }
        }

        $this->cachedHeaderActions = [];
        $this->cacheInteractsWithHeaderActions();
    }

    public function getSubheading(): ?string
    {
        return __('Track your applications, active loan, and repayment progress.');
    }

    public function getFooter(): ?View
    {
        return view('filament.member.partials.filament-action-modals');
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
        return match ($this->hubTab) {
            'active' => array_values(array_filter([
                $this->payOpenPeriodRepaymentAction(),
            ])),
            default => [],
        };
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
            'settleLoan' => $settleLoan,
            'applyUrl' => ApplyForLoan::getUrl(),
            'calculatorUrl' => LoanCalculatorPage::getUrl(),
            'eligible' => (bool) ($eligibility['eligible'] ?? false),
            'eligibilityReason' => $eligibility['reason'] ?? ($eligibility['reasons'][0] ?? null),
            'canRequestEligibilityOverride' => RequestLoanEligibilityOverrideAction::canRequest(),
            'hasPendingEligibilityReview' => RequestLoanEligibilityOverrideAction::hasPendingRequest(),
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
