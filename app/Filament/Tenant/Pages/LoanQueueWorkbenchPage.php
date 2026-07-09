<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Pages;

use App\Filament\Concerns\TranslatesPageNavigationLabel;
use App\Filament\Support\LoanQueueTable;
use App\Filament\Tenant\Support\TenantNavigation;
use App\Models\Tenant\Loan;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use UnitEnum;

class LoanQueueWorkbenchPage extends Page implements HasTable
{
    use InteractsWithTable;
    use TranslatesPageNavigationLabel;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static string|UnitEnum|null $navigationGroup = TenantNavigation::GROUP_FUND_MANAGEMENT;

    protected static ?string $navigationLabel = 'Loan queue';

    protected static ?int $navigationSort = TenantNavigation::SORT_LOAN_QUEUE;

    protected static ?string $slug = 'loan-queue';

    protected static ?string $title = 'Loan queue';

    protected string $view = 'filament.tenant.pages.loan-queue-workbench';

    #[Url(as: 'tab')]
    public string $queueTab = 'needs_decision';

    public static function canAccess(): bool
    {
        return auth()->guard('tenant')->check();
    }

    public function getTitle(): string|Htmlable
    {
        return __('Loan queue');
    }

    public function getSubheading(): ?string
    {
        return __('Review applications and disburse approved loans.');
    }

    public static function getNavigationBadge(): ?string
    {
        $count = Loan::query()->inQueue()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public function setQueueTab(string $tab): void
    {
        if (! in_array($tab, ['needs_decision', 'ready_to_disburse'], true)) {
            return;
        }

        if ($this->queueTab === $tab) {
            return;
        }

        $this->queueTab = $tab;
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        return LoanQueueTable::configure(
            $table->query(fn (): Builder => LoanQueueTable::queueQuery($this->queueTab)),
        );
    }

    /**
     * @return array<string>
     */
    public function getPageClasses(): array
    {
        return [
            ...parent::getPageClasses(),
            'fi-page-loan-queue',
        ];
    }
}
