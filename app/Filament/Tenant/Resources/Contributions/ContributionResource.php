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
    use TranslatesFilamentNavigationLabels;

    protected static ?string $model = Contribution::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyDollar;

    protected static string|UnitEnum|null $navigationGroup = TenantNavigation::GROUP_FUND_MANAGEMENT;

    protected static ?string $navigationLabel = 'Collections';

    protected static ?int $navigationSort = TenantNavigation::SORT_CONTRIBUTIONS;

    public static function getNavigationBadge(): ?string
    {
        $count = Contribution::pending()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    /**
     * @return list<string>
     */
    public static function listTabKeys(): array
    {
        return ['contributions', 'collect', 'collected', 'arrears'];
    }

    public static function normalizeListTab(string $tab): string
    {
        if ($tab === 'ledger') {
            return 'contributions';
        }

        return in_array($tab, self::listTabKeys(), true) ? $tab : 'contributions';
    }

    public static function listTabLabel(string $tab): string
    {
        $tab = self::normalizeListTab($tab);

        return match ($tab) {
            'collect' => __('To collect'),
            'collected' => __('Collected'),
            'arrears' => __('Arrears'),
            default => __('Contributions'),
        };
    }

    public static function listTabUrl(string $tab): string
    {
        return static::listUrl($tab);
    }

    /**
     * Contributions list URL with optional tab and Filament table filter state (URL key `filters`).
     *
     * @param  array<string, array<string, mixed>>  $filters
     */
    public static function listUrl(string $tab = 'contributions', array $filters = [], ?string $cycle = null): string
    {
        $tab = self::normalizeListTab($tab);
        $parameters = [];

        if ($tab !== 'contributions') {
            $parameters['tab'] = $tab;
        }

        $cycle ??= self::resolveListCycleKey();

        if (filled($cycle)) {
            $parameters['cycle'] = $cycle;
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
        return static::listUrl('arrears', static::memberFilter($member));
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
        return app(LoanDelinquencyService::class)
            ->countContributionArrearsPeriods($memberId);
    }

    public static function ledgerUrlForMember(int|Member $member): string
    {
        return static::contributionsUrlForMember($member);
    }

    public static function contributionsUrlForMember(int|Member $member): string
    {
        return static::listUrl('contributions', static::memberFilter($member));
    }

    public static function resolveListTab(): string
    {
        $livewire = Livewire::current();

        if ($livewire instanceof ListContributions && filled($livewire->activeTab)) {
            $tab = $livewire->activeTab;
        } else {
            $tab = request()->string('tab')->toString() ?: 'contributions';
        }

        return self::normalizeListTab($tab);
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
        return app(ContributionCycleService::class)
            ->pendingMembersQueryForPeriod($month, $year)
            ->count();
    }

    public static function openCyclePendingCount(): int
    {
        return once(function (): int {
            $cycles = app(ContributionCycleService::class);
            [$month, $year] = $cycles->currentOpenPeriod();

            return self::pendingCountForPeriod($month, $year);
        });
    }

    public static function form(Schema $schema): Schema
    {
        return ContributionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return match (self::resolveListTab()) {
            'collect' => ContributionCycleTables::configurePendingMembersTable($table),
            'collected' => ContributionCycleTables::configureCollectedTable($table),
            'arrears' => LoanDelinquencyTables::configureContributionArrearsTable(
                $table->pluralModelLabel(UiLabelIcons::tableModelLabel(__('Contribution arrears'))),
            ),
            default => ContributionsTable::configure($table),
        };
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
