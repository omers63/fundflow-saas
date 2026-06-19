<?php

declare(strict_types=1);

namespace App\Filament\Member\Pages;

use App\Filament\Concerns\TranslatesPageNavigationLabel;
use App\Filament\Member\Resources\MyCashOutRequests\MyCashOutRequestResource;
use App\Filament\Member\Resources\MyFundPostings\Schemas\MyFundPostingForm;
use App\Filament\Member\Support\MemberNavigation;
use App\Services\FundPostingService;
use App\Services\MemberCashOutService;
use App\Support\Insights\InsightFormatter;
use App\Support\Tenant\CurrentMember;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class CashAccountPage extends Page implements HasForms
{
    use InteractsWithForms;
    use TranslatesPageNavigationLabel;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWallet;

    protected static ?string $navigationLabel = 'Cash account';

    protected static string|\UnitEnum|null $navigationGroup = MemberNavigation::GROUP_MY_ACCOUNTS;

    protected static ?int $navigationSort = MemberNavigation::SORT_CASH_ACCOUNT;

    protected static ?string $slug = 'cash-account';

    protected string $view = 'filament.member.pages.cash-account';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $depositData = [];

    public static function canAccess(): bool
    {
        return CurrentMember::get() !== null;
    }

    public function getTitle(): string
    {
        return __('Cash account');
    }

    public function getSubheading(): ?string
    {
        return __('Available cash for contributions, deposits, and repayments.');
    }

    public function mount(): void
    {
        $this->depositForm->fill([]);
    }

    public function depositForm(Schema $schema): Schema
    {
        return MyFundPostingForm::configure($schema)->statePath('depositData');
    }

    public function submitDeposit(): void
    {
        $member = CurrentMember::get();

        if ($member === null) {
            return;
        }

        $data = $this->depositForm->getState();

        app(FundPostingService::class)->submit(
            member: $member,
            amount: (float) $data['amount'],
            postingDate: $data['posting_date'],
            reference: $data['reference'] ?? null,
            attachment: $data['attachment'] ?? null,
            comments: $data['comments'] ?? null,
        );

        $this->depositForm->fill([]);

        Notification::make()
            ->title(__('Deposit submitted'))
            ->body(__('Your request has been sent to the admin for review.'))
            ->success()
            ->send();

        $this->dispatch('$refresh');
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $member = CurrentMember::get();
        $cashOut = app(MemberCashOutService::class);
        $currency = InsightFormatter::currency();
        $balance = $member?->getCashBalance() ?? 0.0;
        $available = $member !== null ? $cashOut->availableCashForWithdrawal($member) : 0.0;
        $reserved = $member !== null ? $cashOut->reservedForNextEmi($member) : 0.0;

        return [
            'currency' => $currency,
            'balance' => $balance,
            'available' => $available,
            'reserved' => $reserved > 0 ? $reserved : null,
            'memberNumber' => $member?->member_number,
            'accountId' => $member?->cashAccount?->id,
        ];
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('cashOut')
                ->label(__('Request cash out'))
                ->icon('heroicon-o-arrow-up-tray')
                ->url(MyCashOutRequestResource::getUrl('create')),
        ];
    }
}
