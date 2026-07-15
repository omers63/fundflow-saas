<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Pages;

use App\Filament\Concerns\TranslatesPageNavigationLabel;
use App\Filament\Support\LoanQueueTable;
use App\Filament\Tenant\Support\TenantNavigation;
use App\Models\Tenant\Loan;
use App\Services\Loans\LoanQueueService;
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

    public const TABS = ['intake', 'tiers', 'process'];

    #[Url(as: 'tab')]
    public string $queueTab = 'intake';

    protected ?LoanQueueService $queueService = null;

    public static function canAccess(): bool
    {
        return auth()->guard('tenant')->check();
    }

    public function mount(): void
    {
        $this->queueTab = self::normalizeTab($this->queueTab);
    }

    public function getTitle(): string|Htmlable
    {
        return __('Loan queue');
    }

    public function getSubheading(): ?string
    {
        return __('Triage applications, track tier queues, and disburse fundable loans.');
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
        $tab = self::normalizeTab($tab);

        if ($this->queueTab === $tab) {
            return;
        }

        $this->queueTab = $tab;

        if ($tab !== 'tiers') {
            $this->cachedDefaultTableColumnState = null;
            $this->tableColumns = [];
            $this->resetTable();
        }
    }

    public function getTableColumnsSessionKey(): string
    {
        return 'tables.' . md5(static::class . '|' . $this->queueTab) . '_columns';
    }

    public function getHasReorderedTableColumnsSessionKey(): string
    {
        return 'tables.' . md5(static::class . '|' . $this->queueTab) . '_has_reordered_columns';
    }

    /** Map legacy tab keys (deep links, insights URLs) to the new stage tabs. */
    public static function normalizeTab(string $tab): string
    {
        return match ($tab) {
            'needs_decision' => 'intake',
            'ready_to_disburse' => 'process',
            default => in_array($tab, self::TABS, true) ? $tab : 'intake',
        };
    }

    public function table(Table $table): Table
    {
        return LoanQueueTable::configure(
            $table->query(fn (): Builder => LoanQueueTable::queueQuery($this->queueTab, $this->queue())),
            $this->queueTab,
            $this->queue(),
        );
    }

    /**
     * @return array{intake: int, queued: int, queued_demand: float, disbursable: float, process: int, emergency: int}
     */
    public function getQueueKpis(): array
    {
        return $this->queue()->kpis();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getTierQueues(): array
    {
        return $this->queue()->tierQueues();
    }

    /**
     * @return array<string, string>
     */
    public function getTabLabels(): array
    {
        return [
            'intake' => __('Intake'),
            'tiers' => __('Tier queues'),
            'process' => __('Process queue'),
        ];
    }

    protected function queue(): LoanQueueService
    {
        return $this->queueService ??= app(LoanQueueService::class);
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
