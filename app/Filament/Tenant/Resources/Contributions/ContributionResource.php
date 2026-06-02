<?php

namespace App\Filament\Tenant\Resources\Contributions;

use App\Filament\Concerns\TranslatesFilamentNavigationLabels;
use App\Filament\Support\ContributionCycleTables;
use App\Filament\Support\LoanDelinquencyTables;
use App\Filament\Support\UiLabelIcons;
use App\Filament\Tenant\Resources\Contributions\Pages\CreateContribution;
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
use Livewire\Component;
use Livewire\Livewire;
use UnitEnum;

class ContributionResource extends Resource
{
    use TranslatesFilamentNavigationLabels;

    protected static ?string $model = Contribution::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyDollar;

    protected static string|UnitEnum|null $navigationGroup = TenantNavigation::GROUP_FUND_MANAGEMENT;

    protected static ?int $navigationSort = TenantNavigation::SORT_CONTRIBUTIONS;

    /**
     * @return list<string>
     */
    public static function listTabKeys(): array
    {
        return ['collect', 'collected', 'ledger', 'arrears'];
    }

    public static function listTabLabel(string $tab): string
    {
        return match ($tab) {
            'collect' => __('To collect'),
            'collected' => __('Collected'),
            'arrears' => __('Arrears'),
            default => __('Ledger'),
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
    public static function listUrl(string $tab = 'ledger', array $filters = []): string
    {
        $parameters = [];

        if ($tab !== 'ledger') {
            $parameters['tab'] = $tab;
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

    public static function ledgerUrlForMember(int|Member $member): string
    {
        return static::listUrl('ledger', static::memberFilter($member));
    }

    public static function resolveListTab(): string
    {
        $livewire = Livewire::current();

        if ($livewire instanceof ListContributions && filled($livewire->activeTab)) {
            $tab = $livewire->activeTab;
        } else {
            $tab = request()->string('tab')->toString() ?: 'ledger';
        }

        return in_array($tab, self::listTabKeys(), true) ? $tab : 'ledger';
    }

    public static function openCyclePendingCount(): int
    {
        $cycles = app(ContributionCycleService::class);
        [$month, $year] = $cycles->currentOpenPeriod();

        return $cycles->pendingMembersQueryForPeriod($month, $year)->count();
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

    public static function contributionArrearsPeriodCount(): int
    {
        return count(app(LoanDelinquencyService::class)->contributionArrearsTableRecords());
    }

    public static function getPages(): array
    {
        return [
            'index' => ListContributions::route('/'),
            'create' => CreateContribution::route('/create'),
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
