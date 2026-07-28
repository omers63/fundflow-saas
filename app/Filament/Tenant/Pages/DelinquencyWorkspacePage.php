<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Pages;

use App\Filament\Concerns\TranslatesPageNavigationLabel;
use App\Filament\Support\LoanDelinquencyHeaderActions;
use App\Filament\Support\LoanDelinquencyTables;
use App\Filament\Support\LoanListTableHeaderActions;
use App\Filament\Tenant\Resources\Contributions\ContributionResource;
use App\Filament\Tenant\Resources\Loans\LoanResource;
use App\Filament\Tenant\Resources\Members\MemberResource;
use App\Filament\Tenant\Support\AuditSystemTabRegistry;
use App\Filament\Tenant\Support\DelinquencyTabRegistry;
use App\Filament\Tenant\Support\SettingsTabRegistry;
use App\Filament\Tenant\Support\TenantNavigation;
use App\Models\Tenant\Member;
use App\Services\LoanInsightsService;
use App\Services\Loans\LoanDelinquencyService;
use App\Support\AutomationScheduleSettings;
use App\Support\CollectionInsightsCache;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Url;
use Livewire\Component;
use UnitEnum;

class DelinquencyWorkspacePage extends Page implements HasTable
{
    use InteractsWithTable;
    use TranslatesPageNavigationLabel;

    public const LAST_MAINTENANCE_CACHE_KEY = 'delinquency:last_maintenance_result';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static string|UnitEnum|null $navigationGroup = TenantNavigation::GROUP_FUND_MANAGEMENT;

    protected static ?string $navigationLabel = 'Delinquency';

    protected static ?int $navigationSort = TenantNavigation::SORT_DELINQUENCY;

    protected static ?string $slug = 'delinquency';

    protected static ?string $title = 'Delinquency';

    protected string $view = 'filament.tenant.pages.delinquency-workspace';

    #[Url(as: 'sideTab')]
    public string $sideTab = 'overview';

    #[Url]
    public ?int $memberId = null;

    /**
     * Folded sections that have been expanded (lazy-load expensive content).
     *
     * @var array<string, bool>
     */
    public array $unfoldedSections = [];

    public static function canAccess(): bool
    {
        return auth()->guard('tenant')->check();
    }

    public function mount(): void
    {
        $this->sideTab = DelinquencyTabRegistry::normalize($this->sideTab);
    }

    public function unfoldSection(string $section): void
    {
        $this->unfoldedSections = [
            ...$this->unfoldedSections,
            $section => true,
        ];
    }

    public function isSectionUnfolded(string $section): bool
    {
        return (bool) ($this->unfoldedSections[$section] ?? false);
    }

    public function getTitle(): string|Htmlable
    {
        return __('Delinquency');
    }

    public function getSubheading(): ?string
    {
        return match ($this->sideTab) {
            'overdue' => __('Active loans with installments past due. Run the delinquency check after cycle close to refresh statuses.'),
            'guarantor' => __('Loans in warning or with liability transferred to the guarantor.'),
            'policy' => __('Members who breach consecutive or rolling missed-cycle delinquency policy.'),
            'related' => __('Contribution arrears, member arrears inventory, and policy settings live on their own pages.'),
            default => __('Risk and enforcement: mark overdue installments, review guarantor exposure, and sync policy breaches. Not cycle-scoped.'),
        };
    }

    public static function getNavigationBadge(): ?string
    {
        $total = LoanResource::overdueInstallmentsCount() + LoanResource::guarantorExposureCount();

        return $total > 0 ? (string) $total : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    protected bool $applyingSideTabFromMethod = false;

    public function setSideTab(string $tab): void
    {
        $tab = DelinquencyTabRegistry::normalize($tab);

        if ($this->sideTab === $tab) {
            return;
        }

        $previousTab = $this->sideTab;

        $this->applyingSideTabFromMethod = true;

        try {
            $this->sideTab = $tab;

            if ($tab !== 'overdue') {
                $this->memberId = null;
            }

            $this->syncSideTabTableState($previousTab, $tab);
        } finally {
            $this->applyingSideTabFromMethod = false;
        }
    }

    public function updatedSideTab(): void
    {
        $this->sideTab = DelinquencyTabRegistry::normalize($this->sideTab);

        if ($this->applyingSideTabFromMethod) {
            return;
        }

        if ($this->sideTab !== 'overdue') {
            $this->memberId = null;
        }

        if ($this->showsTable()) {
            $this->tableSort = null;
            $this->reconfigureTableForSideTab();
        }
    }

    /**
     * @return list<string>
     */
    protected function tableSideTabs(): array
    {
        return ['overdue', 'guarantor', 'policy'];
    }

    protected function syncSideTabTableState(string $from, string $to): void
    {
        $tableTabs = $this->tableSideTabs();
        $fromIsTable = in_array($from, $tableTabs, true);
        $toIsTable = in_array($to, $tableTabs, true);

        if (! $fromIsTable && ! $toIsTable) {
            return;
        }

        $this->tableSort = null;

        if ($fromIsTable) {
            $this->unmountTableAction(false);
        }

        // Reconfigure only when landing on a table tab; avoid a second resetTable rebuild.
        if ($toIsTable) {
            $this->reconfigureTableForSideTab();
        }
    }

    protected function reconfigureTableForSideTab(): void
    {
        $this->table = $this->table($this->makeTable());

        $this->cacheSchema('tableFiltersForm', $this->getTableFiltersForm(...));

        // Column manager keeps Livewire + session state keyed by page class. Overdue,
        // guarantor, and policy define different columns; clear before init so names
        // missing from the prior tab's state are not treated as hidden.
        $this->tableColumns = [];
        $this->cachedDefaultTableColumnState = null;
        $this->initTableColumnManager();

        $this->tableFilters = [];
        $this->getTableFiltersForm()->fill([]);
    }

    public function clearMemberFilter(): void
    {
        $this->memberId = null;
        $this->resetTable();
    }

    public function getTableColumnsSessionKey(): string
    {
        return 'tables.'.md5(static::class.'|'.$this->sideTab).'_columns';
    }

    public function getHasReorderedTableColumnsSessionKey(): string
    {
        return 'tables.'.md5(static::class.'|'.$this->sideTab).'_has_reordered_columns';
    }

    public function getTableQueryStringIdentifier(): ?string
    {
        return 'delinquency_'.$this->sideTab;
    }

    public function showsTable(): bool
    {
        return in_array($this->sideTab, ['overdue', 'guarantor', 'policy'], true);
    }

    public function table(Table $table): Table
    {
        return match ($this->sideTab) {
            'guarantor' => LoanDelinquencyTables::configureGuarantorExposureTable($table, $this),
            'policy' => LoanDelinquencyTables::configurePolicyBreachesTable($table, $this),
            default => LoanDelinquencyTables::configureOverdueInstallmentsTable(
                $table,
                $this->memberId,
                $this,
            ),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function insightsSnapshot(): array
    {
        return app(LoanInsightsService::class)->delinquencySnapshot();
    }

    /**
     * @return array{at: string, result: array<string, int>}|null
     */
    public function lastMaintenanceRun(): ?array
    {
        $payload = Cache::get(self::LAST_MAINTENANCE_CACHE_KEY);

        return is_array($payload) && isset($payload['at'], $payload['result'])
            ? $payload
            : null;
    }

    /**
     * @return list<array{label: string, description: string, url: string, badge: ?string, tone: string}>
     */
    public function relatedLinks(): array
    {
        $delinquency = app(LoanDelinquencyService::class);
        $counts = $delinquency->digestCounts();

        return [
            [
                'label' => __('Contributions arrears'),
                'description' => __('Unpaid contribution periods across cycles.'),
                'url' => ContributionResource::listTabUrl('arrears'),
                'badge' => ($counts['contribution_arrears_periods'] ?? 0) > 0
                    ? (string) $counts['contribution_arrears_periods']
                    : null,
                'tone' => 'amber',
            ],
            [
                'label' => __('Members in arrears'),
                'description' => __('Person rollup of contribution and EMI arrears.'),
                'url' => MemberResource::listTabUrl('delinquent'),
                'badge' => ($counts['members_in_arrears'] ?? 0) > 0
                    ? (string) $counts['members_in_arrears']
                    : null,
                'tone' => 'rose',
            ],
            [
                'label' => __('Delinquency policy'),
                'description' => __('Consecutive and rolling missed-cycle thresholds.'),
                'url' => SettingsTabRegistry::url('collection::tab'),
                'badge' => null,
                'tone' => 'sky',
            ],
            [
                'label' => __('Guarantor & grace rules'),
                'description' => __('Grace cycles and guarantor liability settings.'),
                'url' => SettingsTabRegistry::url('guarantor-rules::tab'),
                'badge' => null,
                'tone' => 'violet',
            ],
            [
                'label' => __('Automation schedule'),
                'description' => __('Digest time and daily loan delinquency check.'),
                'url' => AuditSystemTabRegistry::url('jobs', ['jobsTab' => 'schedule']),
                'badge' => null,
                'tone' => 'gray',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function getTabLabels(): array
    {
        return DelinquencyTabRegistry::tabs();
    }

    /**
     * @return array<string, int>
     */
    public function getTabBadges(): array
    {
        return CollectionInsightsCache::remember(
            CollectionInsightsCache::DOMAIN_DELINQUENCY,
            'tab_badges',
            function (): array {
                $delinquency = app(LoanDelinquencyService::class);

                return [
                    'overdue' => LoanResource::overdueInstallmentsCount(),
                    'guarantor' => LoanResource::guarantorExposureCount(),
                    'policy' => count($delinquency->delinquentMemberIds()),
                ];
            },
        );
    }

    public function filteredMemberLabel(): ?string
    {
        if ($this->memberId === null) {
            return null;
        }

        return Member::query()->whereKey($this->memberId)->value('name');
    }

    public function scheduleHint(): string
    {
        return __('Scheduled check: :schedule · Digest: :digest', [
            'schedule' => AutomationScheduleSettings::loanDefaultsScheduleLabel(),
            'digest' => AutomationScheduleSettings::delinquencyDigestScheduleLabel(),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                LoanListTableHeaderActions::exportGuarantorExposureAction(),
                ...LoanDelinquencyHeaderActions::make(),
            ])
                ->label(__('Delinquency tools'))
                ->icon('heroicon-o-exclamation-triangle')
                ->color('gray')
                ->button(),
            ActionGroup::make([
                Action::make('syncPolicyBreaches')
                    ->label(__('Sync policy breaches'))
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading(__('Sync policy breaches'))
                    ->modalDescription(__('Re-evaluates consecutive and rolling missed-cycle policy for all active members.'))
                    ->action(function (LoanDelinquencyService $delinquency): void {
                        $result = $delinquency->syncMemberDelinquencyStatus();

                        Notification::make()
                            ->title(__('Policy sync complete'))
                            ->body(__('Breaches: :breaches · Clear: :cleared', [
                                'breaches' => $result['delinquent_count'],
                                'cleared' => $result['cleared_count'],
                            ]))
                            ->success()
                            ->send();

                        self::refreshAfterAction($this);
                    }),
            ])
                ->label(__('Policy'))
                ->icon(Heroicon::OutlinedShieldExclamation)
                ->color('gray')
                ->button(),
        ];
    }

    public static function rememberMaintenanceResult(array $result): void
    {
        Cache::put(self::LAST_MAINTENANCE_CACHE_KEY, [
            'at' => now()->toIso8601String(),
            'result' => $result,
        ], now()->addDays(14));
    }

    public static function refreshAfterAction(Component $livewire): void
    {
        LoanResource::flushListCountCaches();
        app(LoanDelinquencyService::class)->forgetArrearsAggregateCaches();
        CollectionInsightsCache::bump(CollectionInsightsCache::DOMAIN_DELINQUENCY);

        if (method_exists($livewire, 'resetTable')) {
            $livewire->resetTable();
        }

        $livewire->dispatch('refresh-loan-insights');
    }

    /**
     * @return array<string>
     */
    public function getPageClasses(): array
    {
        return [
            ...parent::getPageClasses(),
            'fi-page-delinquency',
            'ff-tenant-delinquency-workspace',
        ];
    }
}
