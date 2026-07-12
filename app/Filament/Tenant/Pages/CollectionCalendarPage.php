<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Pages;

use App\Filament\Concerns\TranslatesPageNavigationLabel;
use App\Filament\Tenant\Resources\Contributions\ContributionResource;
use App\Filament\Tenant\Resources\Loans\LoanResource;
use App\Filament\Tenant\Support\TenantNavigation;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Setting;
use App\Services\CollectionCalendarService;
use App\Support\BusinessDay;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use UnitEnum;

class CollectionCalendarPage extends Page
{
    use TranslatesPageNavigationLabel;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = TenantNavigation::GROUP_FUND_MANAGEMENT;

    protected static ?string $navigationLabel = 'Collection calendar';

    protected static ?string $slug = 'collection-calendar';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.tenant.pages.collection-calendar';

    #[Url]
    public int $calendarYear = 0;

    #[Url]
    public int $calendarMonth = 0;

    public ?string $selectedDate = null;

    public function mount(): void
    {
        $today = BusinessDay::today();

        if ($this->calendarYear < 2000 || $this->calendarYear > 2100) {
            $this->calendarYear = (int) $today->year;
        }

        if ($this->calendarMonth < 1 || $this->calendarMonth > 12) {
            $this->calendarMonth = (int) $today->month;
        }
    }

    public function getTitle(): string
    {
        return __('Collection calendar');
    }

    public function getSubheading(): ?string
    {
        return __('Contributions and EMI repayments on one calendar — sky and green for collected, red and amber for due.');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function monthGrid(): array
    {
        return app(CollectionCalendarService::class)->monthGrid($this->calendarYear, $this->calendarMonth);
    }

    public function monthLabel(): string
    {
        return Carbon::create($this->calendarYear, $this->calendarMonth, 1)
            ->locale(app()->getLocale())
            ->translatedFormat('F Y');
    }

    public function selectDate(string $date): void
    {
        $this->selectedDate = $date;
    }

    public function clearSelectedDate(): void
    {
        $this->selectedDate = null;
    }

    public function previousMonth(): void
    {
        $date = Carbon::create($this->calendarYear, $this->calendarMonth, 1)->subMonth();
        $this->calendarYear = (int) $date->year;
        $this->calendarMonth = (int) $date->month;
        $this->selectedDate = null;
    }

    public function nextMonth(): void
    {
        $date = Carbon::create($this->calendarYear, $this->calendarMonth, 1)->addMonth();
        $this->calendarYear = (int) $date->year;
        $this->calendarMonth = (int) $date->month;
        $this->selectedDate = null;
    }

    /**
     * @return Collection<int, LoanInstallment>
     */
    public function selectedDayEmis(): Collection
    {
        if ($this->selectedDate === null) {
            return collect();
        }

        return app(CollectionCalendarService::class)
            ->emisForDate($this->selectedDate);
    }

    /**
     * @return Collection<int, Contribution>
     */
    public function selectedDayContributions(): Collection
    {
        if ($this->selectedDate === null) {
            return collect();
        }

        return app(CollectionCalendarService::class)
            ->contributionsForDate($this->selectedDate);
    }

    public function selectedDayItemCount(): int
    {
        return $this->selectedDayEmis()->count() + $this->selectedDayContributions()->count();
    }

    public function currency(): string
    {
        return Setting::get('general', 'currency', 'USD');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('contributions')
                ->label(__('Contributions to collect'))
                ->icon('heroicon-o-currency-dollar')
                ->url(ContributionResource::listTabUrl('collect')),
            Action::make('emi_list')
                ->label(__('EMI to collect'))
                ->icon('heroicon-o-banknotes')
                ->url(LoanResource::listTabUrl('emi_collect')),
        ];
    }
}
