<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Pages;

use App\Filament\Concerns\TranslatesPageNavigationLabel;
use App\Filament\Tenant\Clusters\LoansCluster;
use App\Filament\Tenant\Resources\Loans\LoanResource;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Setting;
use App\Services\Loans\LoanEmiCollectionCalendarService;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;

class LoanEmiCollectionCalendarPage extends Page
{
    use TranslatesPageNavigationLabel;

    protected static ?string $cluster = LoansCluster::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $navigationLabel = 'Collection calendar';

    protected static ?string $slug = 'emi-collection-calendar';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.tenant.pages.loan-emi-collection-calendar';

    #[Url]
    public int $calendarYear = 0;

    #[Url]
    public int $calendarMonth = 0;

    public ?string $selectedDate = null;

    public function mount(): void
    {
        $now = now();

        if ($this->calendarYear < 2000 || $this->calendarYear > 2100) {
            $this->calendarYear = (int) $now->year;
        }

        if ($this->calendarMonth < 1 || $this->calendarMonth > 12) {
            $this->calendarMonth = (int) $now->month;
        }
    }

    public function getTitle(): string
    {
        return __('EMI collection calendar');
    }

    public function getSubheading(): ?string
    {
        return __('Month view of EMI collections — green paid on, red to be collected.');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function monthGrid(): array
    {
        return app(LoanEmiCollectionCalendarService::class)->monthGrid($this->calendarYear, $this->calendarMonth);
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
    public function selectedDayInstallments()
    {
        if ($this->selectedDate === null) {
            return collect();
        }

        return app(LoanEmiCollectionCalendarService::class)
            ->installmentsForDate(Carbon::parse($this->selectedDate));
    }

    public function currency(): string
    {
        return Setting::get('general', 'currency', 'USD');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('emi_list')
                ->label(__('EMI to collect'))
                ->icon('heroicon-o-banknotes')
                ->url(LoanResource::listTabUrl('emi_collect')),
        ];
    }
}
