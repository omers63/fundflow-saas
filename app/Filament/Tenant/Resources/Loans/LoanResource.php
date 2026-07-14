<?php

namespace App\Filament\Tenant\Resources\Loans;

use App\Filament\Concerns\TranslatesFilamentNavigationLabels;
use App\Filament\Support\LoanDelinquencyTables;
use App\Filament\Support\LoanEmiCollectionTables;
use App\Filament\Tenant\Clusters\LoansCluster;
use App\Filament\Tenant\Pages\LoanQueueWorkbenchPage;
use App\Filament\Tenant\Resources\LoanEligibilityOverrideRequests\Tables\LoanEligibilityOverrideRequestsTable;
use App\Filament\Tenant\Resources\Loans\Pages\CreateLoan;
use App\Filament\Tenant\Resources\Loans\Pages\EditLoan;
use App\Filament\Tenant\Resources\Loans\Pages\ListLoans;
use App\Filament\Tenant\Resources\Loans\Pages\ViewLoan;
use App\Filament\Tenant\Resources\Loans\RelationManagers\DisbursementsRelationManager;
use App\Filament\Tenant\Resources\Loans\RelationManagers\InstallmentsRelationManager;
use App\Filament\Tenant\Resources\Loans\RelationManagers\RepaymentsRelationManager;
use App\Filament\Tenant\Resources\Loans\Schemas\LoanForm;
use App\Filament\Tenant\Resources\Loans\Tables\LoansTable;
use App\Filament\Tenant\Resources\Loans\Widgets\LoanViewInsights;
use App\Filament\Tenant\Widgets\LoanInsightsWidget;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanEligibilityOverrideRequest;
use App\Models\Tenant\Member;
use App\Services\ContributionCycleService;
use App\Services\Loans\LoanDelinquencyService;
use App\Services\Loans\LoanEmiCollectionCatalogService;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use InvalidArgumentException;
use Livewire\Component;
use Livewire\Livewire;

class LoanResource extends Resource
{
    use TranslatesFilamentNavigationLabels;

    protected static ?string $model = Loan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $cluster = LoansCluster::class;

    protected static ?string $navigationLabel = 'Loans';

    protected static ?int $navigationSort = 1;

    /**
     * @var array<string, int>
     */
    private static array $pendingEmiCountCache = [];

    /**
     * @var array<string, int>
     */
    private static array $collectedEmiCountCache = [];

    /**
     * @var array<string, int>
     */
    private static array $emiArrearsCountCache = [];

    /**
     * @var array<string, int>
     */
    private static array $overdueInstallmentsCountCache = [];

    private static ?int $guarantorExposureCountCache = null;

    /**
     * @return list<string>
     */
    public static function primaryTabKeys(): array
    {
        return ['collection', 'portfolio', 'delinquency'];
    }

    /**
     * @return list<string>
     */
    public static function legacyTabKeys(): array
    {
        return [
            'emi_collect',
            'emi_collected',
            'overdue_installments',
            'guarantor_exposure',
            'eligibility_reviews',
        ];
    }

    /**
     * @return list<string>
     */
    public static function listTabKeys(): array
    {
        return [
            ...self::primaryTabKeys(),
            ...self::legacyTabKeys(),
        ];
    }

    public static function normalizePrimaryTab(?string $tab): string
    {
        return match ($tab) {
            'emi_collect', 'emi_collected', 'collection', null, '' => 'collection',
            'overdue_installments', 'guarantor_exposure', 'delinquency' => 'delinquency',
            'eligibility_reviews', 'portfolio' => 'portfolio',
            default => in_array($tab, self::primaryTabKeys(), true) ? $tab : 'collection',
        };
    }

    public static function listTabLabel(string $tab): string
    {
        return match ($tab) {
            'collection', 'emi_collect', 'emi_collected' => __('Collection'),
            'delinquency', 'overdue_installments', 'guarantor_exposure' => __('Delinquency'),
            'eligibility_reviews' => __('Eligibility reviews'),
            default => __('Portfolio'),
        };
    }

    public static function pendingEmiCollectionMemberCount(): int
    {
        [$month, $year] = self::resolveListCycle();
        $cacheKey = sprintf('%04d-%02d', $year, $month);

        if (array_key_exists($cacheKey, self::$pendingEmiCountCache)) {
            return self::$pendingEmiCountCache[$cacheKey];
        }

        return self::$pendingEmiCountCache[$cacheKey] = app(LoanEmiCollectionCatalogService::class)
            ->pendingMemberCount($month, $year);
    }

    public static function collectedEmiInstallmentCount(): int
    {
        [$month, $year] = self::resolveListCycle();
        $cacheKey = sprintf('%04d-%02d', $year, $month);

        if (array_key_exists($cacheKey, self::$collectedEmiCountCache)) {
            return self::$collectedEmiCountCache[$cacheKey];
        }

        return self::$collectedEmiCountCache[$cacheKey] = app(LoanEmiCollectionCatalogService::class)
            ->collectedInstallmentCount($month, $year);
    }

    public static function emiArrearsInstallmentCount(): int
    {
        [$month, $year] = self::resolveListCycle();
        $live = self::isViewingOpenCycle();
        $cacheKey = sprintf('%04d-%02d:%d', $year, $month, $live ? 1 : 0);

        if (array_key_exists($cacheKey, self::$emiArrearsCountCache)) {
            return self::$emiArrearsCountCache[$cacheKey];
        }

        return self::$emiArrearsCountCache[$cacheKey] = app(LoanEmiCollectionCatalogService::class)
            ->emiArrearsInstallmentCount($month, $year, $live);
    }

    public static function listTabUrl(string $tab, array $filters = [], ?string $cycle = null): string
    {
        $cycle ??= self::resolveListCycleKey();

        return match ($tab) {
            'emi_collect' => static::listUrl('collection', $filters, segment: 'collect', cycle: $cycle),
            'emi_collected' => static::listUrl('collection', $filters, segment: 'collected', cycle: $cycle),
            'emi_arrears', 'arrears' => static::listUrl('collection', $filters, segment: 'arrears', cycle: $cycle),
            'overdue_installments' => static::listUrl('delinquency', $filters, view: 'overdue', cycle: $cycle),
            'guarantor_exposure' => static::listUrl('delinquency', $filters, view: 'guarantor', cycle: $cycle),
            'eligibility_reviews' => static::listUrl('portfolio', $filters, portfolioView: 'eligibility', cycle: $cycle),
            default => static::listUrl($tab, $filters, cycle: $cycle),
        };
    }

    /**
     * @param  array<string, array<string, mixed>>  $filters
     */
    public static function listUrl(
        string $tab = 'collection',
        array $filters = [],
        ?string $segment = null,
        ?string $view = null,
        ?string $portfolioView = null,
        ?string $cycle = null,
    ): string {
        $primaryTab = self::normalizePrimaryTab($tab);
        $parameters = [];

        if ($primaryTab !== 'collection') {
            $parameters['tab'] = $primaryTab;
        }

        $cycle ??= self::resolveListCycleKey();

        if (filled($cycle)) {
            $parameters['cycle'] = $cycle;
        }

        $segment ??= match ($tab) {
            'emi_collect', 'collect' => 'collect',
            'emi_collected', 'collected' => 'collected',
            'emi_arrears', 'arrears' => 'arrears',
            default => null,
        };

        if ($primaryTab === 'collection' && filled($segment) && $segment !== 'collect') {
            $parameters['segment'] = $segment;
        }

        $view ??= match ($tab) {
            'overdue_installments', 'overdue' => 'overdue',
            'guarantor_exposure', 'guarantor' => 'guarantor',
            default => null,
        };

        if ($primaryTab === 'delinquency' && filled($view) && $view !== 'overdue') {
            $parameters['view'] = $view;
        }

        $portfolioView ??= $tab === 'eligibility_reviews' ? 'eligibility' : null;

        if ($primaryTab === 'portfolio' && $portfolioView === 'eligibility') {
            $parameters['portfolioView'] = 'eligibility';
        }

        if ($filters !== []) {
            $parameters['filters'] = $filters;
        }

        return static::getUrl('index', $parameters, panel: 'tenant');
    }

    public static function listCollectionSegmentUrl(string $segment, ?string $cycle = null): string
    {
        return static::listUrl('collection', segment: $segment, cycle: $cycle ?? self::resolveListCycleKey());
    }

    public static function listDelinquencyViewUrl(string $view): string
    {
        return static::listUrl('delinquency', view: $view);
    }

    public static function listPortfolioViewUrl(?string $portfolioView = null): string
    {
        return static::listUrl('portfolio', portfolioView: $portfolioView);
    }

    /**
     * @return array<string, array<string, string>>
     */
    public static function memberFilter(int|Member $member): array
    {
        $memberId = $member instanceof Member ? $member->getKey() : $member;

        return [
            'member_id' => [
                'value' => (string) $memberId,
            ],
        ];
    }

    public static function portfolioUrlForMember(int|Member $member, ?string $status = null): string
    {
        $filters = static::memberFilter($member);

        if ($status !== null) {
            $filters['status'] = ['value' => $status];
        }

        return static::listUrl('portfolio', $filters);
    }

    public static function queueUrl(string $tab = 'needs_decision'): string
    {
        $parameters = $tab !== 'needs_decision' ? ['tab' => $tab] : [];

        return LoanQueueWorkbenchPage::getUrl($parameters);
    }

    public static function resolveListCycleKey(): ?string
    {
        $livewire = Livewire::current();

        if ($livewire instanceof ListLoans && filled($livewire->selectedCycle)) {
            return $livewire->selectedCycle;
        }

        $fromRequest = request()->string('cycle')->toString();

        return $fromRequest !== '' ? $fromRequest : null;
    }

    /**
     * @return array{0: int, 1: int}
     */
    public static function resolveListCycle(): array
    {
        $cycles = app(ContributionCycleService::class);
        $key = self::resolveListCycleKey();

        if (filled($key)) {
            try {
                return $cycles->parseContributionCycleKey($key);
            } catch (InvalidArgumentException) {
            }
        }

        return $cycles->currentOpenPeriod();
    }

    public static function resolveListCycleLabel(): string
    {
        [$month, $year] = self::resolveListCycle();

        return app(ContributionCycleService::class)->periodLabel($month, $year);
    }

    public static function isViewingOpenCycle(): bool
    {
        $cycles = app(ContributionCycleService::class);
        [$selectedMonth, $selectedYear] = self::resolveListCycle();
        [$openMonth, $openYear] = $cycles->currentOpenPeriod();

        return $selectedMonth === $openMonth && $selectedYear === $openYear;
    }

    public static function listWithCycle(?string $cycleKey): string
    {
        $primary = self::resolvePrimaryTab();

        return match ($primary) {
            'portfolio' => self::listUrl('portfolio', cycle: $cycleKey, portfolioView: self::resolvePortfolioView()),
            'delinquency' => self::listUrl('delinquency', cycle: $cycleKey, view: self::resolveDelinquencyView()),
            default => self::listUrl('collection', cycle: $cycleKey, segment: self::resolveCollectionSegment()),
        };
    }

    public static function overdueInstallmentsUrlForMember(int|Member $member): string
    {
        return static::listUrl('delinquency', static::memberFilter($member), view: 'overdue');
    }

    public static function resolvePrimaryTab(): string
    {
        $livewire = Livewire::current();

        if ($livewire instanceof ListLoans && filled($livewire->activeTab)) {
            $tab = $livewire->activeTab;
        } else {
            $tab = request()->string('tab')->toString() ?: 'collection';
        }

        return self::normalizePrimaryTab($tab);
    }

    /**
     * @deprecated Use {@see resolvePrimaryTab()} or {@see tableLayoutKey()}.
     */
    public static function resolveListTab(): string
    {
        return self::tableLayoutKey();
    }

    public static function resolveCollectionSegment(): string
    {
        $livewire = Livewire::current();

        if ($livewire instanceof ListLoans && filled($livewire->collectionSegment)) {
            return in_array($livewire->collectionSegment, ['collect', 'collected', 'arrears'], true)
                ? $livewire->collectionSegment
                : 'collect';
        }

        $segment = request()->string('segment')->toString();

        if (in_array($segment, ['collect', 'collected', 'arrears'], true)) {
            return $segment;
        }

        return match (request()->string('tab')->toString()) {
            'emi_collected', 'collected' => 'collected',
            'emi_collect', 'collect' => 'collect',
            'arrears' => 'arrears',
            default => 'collect',
        };
    }

    public static function resolveDelinquencyView(): string
    {
        if (self::resolvePrimaryTab() !== 'delinquency') {
            return 'overdue';
        }

        $livewire = Livewire::current();

        if ($livewire instanceof ListLoans && filled($livewire->delinquencyView)) {
            return in_array($livewire->delinquencyView, ['overdue', 'guarantor'], true)
                ? $livewire->delinquencyView
                : 'overdue';
        }

        $view = request()->string('view')->toString();

        if (in_array($view, ['overdue', 'guarantor'], true)) {
            return $view;
        }

        return match (request()->string('tab')->toString()) {
            'guarantor_exposure', 'guarantor' => 'guarantor',
            'overdue_installments', 'overdue' => 'overdue',
            default => 'overdue',
        };
    }

    public static function resolvePortfolioView(): ?string
    {
        if (self::resolvePrimaryTab() !== 'portfolio') {
            return null;
        }

        $livewire = Livewire::current();

        if ($livewire instanceof ListLoans && filled($livewire->portfolioView)) {
            return $livewire->portfolioView === 'eligibility' ? 'eligibility' : null;
        }

        if (request()->string('portfolioView')->toString() === 'eligibility') {
            return 'eligibility';
        }

        if (request()->string('tab')->toString() === 'eligibility_reviews') {
            return 'eligibility';
        }

        return null;
    }

    public static function tableLayoutKey(): string
    {
        return match (self::resolvePrimaryTab()) {
            'collection' => 'collection|'.self::resolveCollectionSegment(),
            'delinquency' => 'delinquency|'.self::resolveDelinquencyView(),
            'portfolio' => self::resolvePortfolioView() === 'eligibility'
            ? 'portfolio|eligibility'
            : 'portfolio',
            default => 'portfolio',
        };
    }

    public static function resolveInsightsContext(): string
    {
        return match (self::tableLayoutKey()) {
            'collection|collect' => 'emi_collect',
            'collection|collected' => 'emi_collected',
            'collection|arrears' => 'emi_arrears',
            'delinquency|overdue', 'delinquency|guarantor' => 'delinquency',
            'portfolio|eligibility' => 'eligibility_reviews',
            default => 'portfolio',
        };
    }

    public static function form(Schema $schema): Schema
    {
        return LoanForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        $livewire = Livewire::current();

        return match (self::tableLayoutKey()) {
            'collection|collect' => LoanEmiCollectionTables::configurePendingMembersTable($table),
            'collection|collected' => LoanEmiCollectionTables::configureCollectedTable($table),
            'collection|arrears' => LoanEmiCollectionTables::configureArrearsTable($table),
            'delinquency|overdue' => LoanDelinquencyTables::configureOverdueInstallmentsTable($table),
            'delinquency|guarantor' => LoanDelinquencyTables::configureGuarantorExposureTable(
                $table,
                $livewire instanceof ListLoans ? $livewire : null,
            ),
            'portfolio|eligibility' => LoanEligibilityOverrideRequestsTable::configure($table),
            default => LoansTable::configure($table),
        };
    }

    public static function pendingEligibilityReviewsCount(): int
    {
        if (! LoanEligibilityOverrideRequest::isTableReady()) {
            return 0;
        }

        return LoanEligibilityOverrideRequest::pending()->count();
    }

    public static function getRelations(): array
    {
        return [
            InstallmentsRelationManager::class,
            DisbursementsRelationManager::class,
            RepaymentsRelationManager::class,
        ];
    }

    /**
     * Exclude the queue page so cluster sub-nav can highlight "Loan queue" separately.
     *
     * @return string|array<string>
     */
    public static function getNavigationItemActiveRoutePattern(): string|array
    {
        $base = static::getRouteBaseName();

        return [
            "{$base}.index",
            "{$base}.create",
            "{$base}.view",
            "{$base}.edit",
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLoans::route('/'),
            'create' => CreateLoan::route('/create'),
            'view' => ViewLoan::route('/{record}'),
            'edit' => EditLoan::route('/{record}/edit'),
        ];
    }

    public static function flushListCountCaches(): void
    {
        self::$pendingEmiCountCache = [];
        self::$collectedEmiCountCache = [];
        self::$emiArrearsCountCache = [];
        self::$overdueInstallmentsCountCache = [];
        self::$guarantorExposureCountCache = null;
    }

    public static function overdueInstallmentsCount(): int
    {
        $cacheKey = 'all';

        if (array_key_exists($cacheKey, self::$overdueInstallmentsCountCache)) {
            return self::$overdueInstallmentsCountCache[$cacheKey];
        }

        return self::$overdueInstallmentsCountCache[$cacheKey] = LoanDelinquencyTables::overdueInstallmentsQuery()->count();
    }

    public static function guarantorExposureCount(): int
    {
        if (self::$guarantorExposureCountCache !== null) {
            return self::$guarantorExposureCountCache;
        }

        return self::$guarantorExposureCountCache = (int) app(LoanDelinquencyService::class)->loansAtGuarantorRiskCount();
    }

    public static function dispatchInsightsRefresh(?Component $livewire): void
    {
        if ($livewire === null) {
            return;
        }

        self::flushListCountCaches();

        static::refreshLoanPageRecord($livewire);

        $factory = app('livewire.factory');
        $widgetNames = array_map(
            fn (string $class): string => json_encode(
                $factory->resolveComponentName($class),
                JSON_THROW_ON_ERROR,
            ),
            [
                LoanInsightsWidget::class,
                LoanViewInsights::class,
            ],
        );

        $refreshWidgetsJs = implode('', array_map(
            fn (string $name): string => "window.Livewire.getByName({$name}).forEach(w => w.\$refresh());",
            $widgetNames,
        ));

        $livewire->js('setTimeout(() => { '.$refreshWidgetsJs.' $wire.$refresh(); }, 0)');
    }

    private static function refreshLoanPageRecord(Component $livewire): void
    {
        if (! method_exists($livewire, 'getRecord')) {
            return;
        }

        $record = $livewire->getRecord();

        if (! $record instanceof Loan) {
            return;
        }

        $record->refresh();

        if (method_exists($livewire, 'refreshRecordAndForm')) {
            $livewire->refreshRecordAndForm();
        }
    }
}
