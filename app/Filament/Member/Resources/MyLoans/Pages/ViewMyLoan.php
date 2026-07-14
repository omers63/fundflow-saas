<?php

declare(strict_types=1);

namespace App\Filament\Member\Resources\MyLoans\Pages;

use App\Filament\Member\Resources\MyLoans\MyLoanResource;
use App\Filament\Support\MemberLoanFilamentActions;
use App\Models\Tenant\Loan;
use App\Services\MemberLoansHubService;
use App\Support\Insights\InsightFormatter;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\View as SchemaView;
use Filament\Schemas\Schema;

class ViewMyLoan extends ViewRecord
{
    protected static string $resource = MyLoanResource::class;

    public function getHeading(): string
    {
        return __('Loan #:id', ['id' => $this->record->getKey()]);
    }

    public function getSubheading(): ?string
    {
        return Loan::statusOptions()[$this->record->status] ?? $this->record->status;
    }

    protected function getHeaderActions(): array
    {
        $actions = [
            Action::make('backToHub')
                ->label(__('Back to my loans'))
                ->icon('heroicon-o-arrow-left')
                ->url(MyLoanResource::getUrl('index'))
                ->color('gray'),
        ];

        if ($this->record->status === 'active') {
            $actions[] = $this->payOpenPeriodRepaymentAction();
            $actions[] = $this->earlySettleAction();
        }

        return $actions;
    }

    public function payOpenPeriodRepaymentAction(): Action
    {
        return MemberLoanFilamentActions::payOpenPeriodRepayment()
            ->record($this->getRecord());
    }

    public function earlySettleAction(): Action
    {
        return MemberLoanFilamentActions::earlySettle()
            ->record($this->getRecord());
    }

    public function openEarlySettlement(?int $loanId = null): void
    {
        if ($this->record->status !== 'active') {
            Notification::make()
                ->title(__('No active loan'))
                ->body(__('You do not have an active loan to settle right now.'))
                ->warning()
                ->send();

            return;
        }

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

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            SchemaView::make('filament.member.resources.my-loans.pages.view-loan-shell')
                ->viewData(fn (): array => $this->getLoanViewData()),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function getLoanViewData(): array
    {
        $loan = $this->getRecord();
        $hub = app(MemberLoansHubService::class);

        $card = in_array($loan->status, MemberLoansHubService::historyStatuses(), true)
            ? $hub->historyLoanCard($loan)
            : $hub->loanCard($loan);

        return [
            'loan' => $card,
            'currency' => InsightFormatter::currency(),
            'showSchedule' => $card['show_schedule'] ?? false,
        ];
    }
}
