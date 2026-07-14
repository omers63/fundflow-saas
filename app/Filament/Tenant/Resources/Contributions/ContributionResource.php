<?php

namespace App\Filament\Tenant\Resources\Contributions;

use App\Filament\Concerns\TranslatesFilamentNavigationLabels;
use App\Filament\Support\ContributionCycleTables;
use App\Filament\Support\LoanDelinquencyTables;
use App\Filament\Support\UiLabelIcons;
use App\Filament\Tenant\Resources\Contributions\Pages\CreateContribution;
use App\Filament\Tenant\Resources\Contributions\Pages\EditContribution;
use App\Filament\Tenant\Resources\Contributions\Pages\ListContributions;
use App\Filament\Tenant\Resources\Contributions\Schemas\ContributionForm;
use App\Filament\Tenant\Resources\Contributions\Tables\ContributionsTable;
use App\Filament\Tenant\Support\TenantNavigation;
use App\Filament\Tenant\Widgets\ContributionInsightsWidget;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\Member;
use App\Services\ContributionCycleService;
use App\Services\Loans\LoanDelinquencyService;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use InvalidArgumentException;
use Livewire\Component;
use Livewire\Livewire;
use UnitEnum;

class ContributionResource extends Resource
{
    /** @var array<string, int> */
    private static array $pendingCountCache = [];

    /** @var array<string, int> */
    private static array $arrearsPeriodCountCache = [];

    use TranslatesFilamentNavigationLabels;

    protected static ?string $model = Contribution::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyDollar;

    protected static string|UnitEnum|null $navigationGroup = TenantNavigation::GROUP_FUND_MANAGEMENT;

    protected static ?string $navigationLabel = 'Contributions';

    protected static ?int $navigationSort = TenantNavigation::SORT_CONTRIBUTIONS;

    public static function getNavigationBadge(): ?string
    {
        $count = self::openCyclePendingCount();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    /**
     * @return list<string>
     */
    public static function primaryTabKeys(): array
    {
        return ['cycle', 'ledger'];
    }

    /**
     * @return list<string>
     */
    public static function legacyTabKeys(): array
    {
        return ['contributions', 'collect', 'collected', 'arrears'];
    }

    public static function normalizePrimaryTab(?string $tab): string
    {
        return match ($tab) {
            'contributions', 'ledger', 'arrears' => 'ledger',
            'collect', 'collected', 'cycle', null, '' => 'cycle',
            default => in_array($tab, self::primaryTabKeys(), true) ? $tab : 'cycle',
        };
    }

    /**
     * @deprecated Use {@see resolveInsightsContext()} for widget snapshots.
     */
    public static function normalizeListTab(string $tab): string
    {
        return match ($tab) {
            'ledger' => 'contributions',
            'cycle' => 'collect',
            default => in_array($tab, ['collect', 'collected', 'arrears', 'contributions'], true) ? $tab : 'contributions',
        };
    }

    public static function listTabLabel(string $tab): string
    {
        return match ($tab) {
            'cycle' => __('Collection'),
            'ledger', 'contributions' => __('Ledger'),
            'collect' => __('To collect'),
            'collected' => __('Collected'),
            'arrears' => __('Arrears'),
            default => __('Contributions'),
        };
    }

    public static function listTabUrl(string $tab, ?string $cycle = null): string
    {
        return match ($tab) {
            'collect' => static::listUrl('cycle', cycle: $cycle, segment: 'collect'),
            'collected' => static::listUrl('cycle', cycle: $cycle, segment: 'collected'),
            'arrears' => static::listUrl('cycle', cycle: $cycle, segment: 'arrears'),
            'contributions', 'ledger' => static::listUrl('ledger', cycle: $cycle),
            default => static::listUrl($tab, cycle: $cycle),
        };
    }

    /**
     * @param  array<string, array<string, mixed>>  $filters
     */
    public static function listUrl(
        string $tab = 'cycle',
        array $filters = [],
        ?string $cycle = null,
        ?string $segment = null,
        ?string $view = null,
    ): string {
        $primaryTab = self::normalizePrimaryTab($tab);
        $parameters = [];

        if ($primaryTab !== 'cycle') {
            $parameters['tab'] = $primaryTab;
        }

        $cycle ??= self::resolveListCycleKey();

        if (filled($cycle)) {
            $parameters['cycle'] = $cycle;
        }

        $segment ??= match ($tab) {
            'collect' => 'collect',
            'collected' => 'collected',
            default => null,
        };

        if ($primaryTab === 'cycle' && filled($segment) && $segment !== 'collect') {
            $parameters['segment'] = $segment;
        }

        $view ??= $tab === 'arrears' ? 'arrears' : null;

        if ($primaryTab === 'ledger' && $view === 'arrears') {
            $parameters['view'] = 'arrears';
        }

        if ($filters !== []) {
            $parameters['filters'] = $filters;
        }

        return static::getUrl('index', $parameters);
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

    public static function arrearsUrlForMember(int|Member $member): string
    {
        return static::listUrl('cycle', static::memberFilter($member), segment: 'arrears');
    }

    public static function memberFilterFromRequest(): ?int
    {
        $value = request()->input('filters.member_id.value')
            ?? request()->input('tableFilters.member_id.value');

        if (blank($value)) {
            return null;
        }

        $memberId = (int) $value;

        return $memberId > 0 ? $memberId : null;
    }

    public static function contributionArrearsPeriodCount(?int $memberId = null): int
    {
        $throughMonth = null;
        $throughYear = null;
        $live = null;

        if (Livewire::current() instanceof ListContributions) {
            [$throughMonth, $throughYear] = self::resolveListCycle();
            $live = self::isViewingOpenCycle();
        }

        $cacheKey = sprintf(
            '%s|%s|%s|%s',
            $memberId ?? 'all',
            $throughMonth ?? 'open',
            $throughYear ?? 'open',
            $live === null ? 'na' : ($live ? 'live' : 'past'),
        );

        if (array_key_exists($cacheKey, self::$arrearsPeriodCountCache)) {
            return self::$arrearsPeriodCountCache[$cacheKey];
        }

        return self::$arrearsPeriodCountCache[$cacheKey] = app(LoanDelinquencyService::class)
            ->countContributionArrearsPeriods(
                $memberId,
                $throughMonth,
                $throughYear,
                $live,
            );
    }

    public static function ledgerUrlForMember(int|Member $member): string
    {
        return static::contributionsUrlForMember($member);
    }

    public static function contributionsUrlForMember(int|Member $member): string
    {
        return static::listUrl('ledger', static::memberFilter($member));
    }

    public static function resolvePrimaryTab(): string
    {
        $livewire = Livewire::current();

        if ($livewire instanceof ListContributions && filled($livewire->activeTab)) {
            $tab = $livewire->activeTab;
        } else {
            $tab = request()->string('tab')->toString() ?: 'cycle';
        }

        return self::normalizePrimaryTab($tab);
    }

    public static function resolveCycleSegment(): string
    {
        $livewire = Livewire::current();

        if ($livewire instanceof ListContributions && filled($livewire->cycleSegment)) {
            return in_array($livewire->cycleSegment, ['collect', 'collected', 'arrears'], true)
                ? $livewire->cycleSegment
                : 'collect';
        }

        $segment = request()->string('segment')->toString();

        if (in_array($segment, ['collect', 'collected', 'arrears'], true)) {
            return $segment;
        }

        return match (request()->string('tab')->toString()) {
            'collected' => 'collected',
            'collect' => 'collect',
            'arrears' => 'arrears',
            default => 'collect',
        };
    }

    public static function resolveLedgerView(): ?string
    {
        if (self::resolvePrimaryTab() !== 'ledger') {
            return null;
        }

        $livewire = Livewire::current();

        if ($livewire instanceof ListContributions && filled($livewire->ledgerView)) {
            return $livewire->ledgerView === 'arrears' ? 'arrears' : null;
        }

        if (request()->string('view')->toString() === 'arrears') {
            return 'arrears';
        }

        if (request()->string('tab')->toString() === 'arrears') {
            return 'arrears';
        }

        return null;
    }

    /**
     * Insight/widget context: collect, collected, contributions, arrears.
     */
    public static function resolveInsightsContext(): string
    {
        if (self::resolvePrimaryTab() === 'ledger') {
            return self::resolveLedgerView() === 'arrears' ? 'arrears' : 'contributions';
        }

        return self::resolveCycleSegment();
    }

    /**
     * @deprecated Use {@see resolveInsightsContext()}.
     */
    public static function resolveListTab(): string
    {
        return self::resolveInsightsContext();
    }

    public static function resolveListCycleKey(): ?string
    {
        $livewire = Livewire::current();

        if ($livewire instanceof ListContributions && filled($livewire->selectedCycle)) {
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

    public static function pendingCountForPeriod(int $month, int $year): int
    {
        $cacheKey = $month.'-'.$year;

        if (array_key_exists($cacheKey, self::$pendingCountCache)) {
            return self::$pendingCountCache[$cacheKey];
        }

        return self::$pendingCountCache[$cacheKey] = app(ContributionCycleService::class)
            ->pendingMembersQueryForPeriod($month, $year)
            ->count();
    }

    public static function flushPeriodCountCaches(): void
    {
        self::$pendingCountCache = [];
        self::$arrearsPeriodCountCache = [];
    }

    public static function collectedContributionCount(): int
    {
        [$month, $year] = self::resolveListCycle();

        return app(ContributionCycleService::class)
            ->postedContributionCount($month, $year);
    }

    public static function openCyclePendingCount(): int
    {
        $cycles = app(ContributionCycleService::class);
        [$month, $year] = $cycles->currentOpenPeriod();

        return self::pendingCountForPeriod($month, $year);
    }

    public static function tableLayoutKey(): string
    {
        if (self::resolvePrimaryTab() === 'cycle') {
            return 'cycle|'.self::resolveCycleSegment();
        }

        return self::resolveLedgerView() === 'arrears' ? 'ledger|arrears' : 'ledger';
    }

    public static function listCycleSegmentUrl(string $segment, ?string $cycle = null): string
    {
        return static::listUrl('cycle', cycle: $cycle, segment: $segment);
    }

    public static function listLedgerViewUrl(?string $view = null, ?string $cycle = null): string
    {
        return static::listUrl('ledger', cycle: $cycle, view: $view);
    }

    public static function listWithCycle(?string $cycleKey): string
    {
        $primary = self::resolvePrimaryTab();

        return match ($primary) {
            'ledger' => self::listUrl('ledger', cycle: $cycleKey, view: self::resolveLedgerView()),
            default => self::listUrl('cycle', cycle: $cycleKey, segment: self::resolveCycleSegment()),
        };
    }

    public static function form(Schema $schema): Schema
    {
        return ContributionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        if (self::resolvePrimaryTab() === 'cycle') {
            return match (self::resolveCycleSegment()) {
                'collected' => ContributionCycleTables::configureCollectedTable($table),
                'arrears' => LoanDelinquencyTables::configureContributionArrearsTable(
                    $table->pluralModelLabel(UiLabelIcons::tableModelLabel(__('Contribution arrears'))),
                ),
                default => ContributionCycleTables::configurePendingMembersTable($table),
            };
        }

        if (self::resolveLedgerView() === 'arrears') {
            return LoanDelinquencyTables::configureContributionArrearsTable(
                $table->pluralModelLabel(UiLabelIcons::tableModelLabel(__('Contribution arrears'))),
            );
        }

        return ContributionsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListContributions::route('/'),
            'create' => CreateContribution::route('/create'),
            'edit' => EditContribution::route('/{record}/edit'),
        ];
    }

    public static function dispatchInsightsRefresh(?Component $livewire): void
    {
        self::flushPeriodCountCaches();

        if ($livewire === null) {
            return;
        }

        $targetName = json_encode(
            app('livewire.factory')->resolveComponentName(ContributionInsightsWidget::class),
            JSON_THROW_ON_ERROR
        );

        $livewire->js(
            'setTimeout(() => window.Livewire.getByName('.$targetName.').forEach(w => w.$refresh()), 0)'
        );
    }
}
