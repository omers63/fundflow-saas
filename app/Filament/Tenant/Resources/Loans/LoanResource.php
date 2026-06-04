<?php

namespace App\Filament\Tenant\Resources\Loans;

use App\Filament\Concerns\TranslatesFilamentNavigationLabels;
use App\Filament\Support\LoanDelinquencyTables;
use App\Filament\Support\LoanEmiCollectionTables;
use App\Filament\Tenant\Clusters\LoansCluster;
use App\Filament\Tenant\Resources\LoanEligibilityOverrideRequests\Tables\LoanEligibilityOverrideRequestsTable;
use App\Filament\Tenant\Resources\Loans\Pages\CreateLoan;
use App\Filament\Tenant\Resources\Loans\Pages\EditLoan;
use App\Filament\Tenant\Resources\Loans\Pages\ListLoanQueue;
use App\Filament\Tenant\Resources\Loans\Pages\ListLoans;
use App\Filament\Tenant\Resources\Loans\Pages\ViewLoan;
use App\Filament\Tenant\Resources\Loans\RelationManagers\DisbursementsRelationManager;
use App\Filament\Tenant\Resources\Loans\RelationManagers\InstallmentsRelationManager;
use App\Filament\Tenant\Resources\Loans\RelationManagers\RepaymentsRelationManager;
use App\Filament\Tenant\Resources\Loans\Schemas\LoanForm;
use App\Filament\Tenant\Resources\Loans\Tables\LoansTable;
use App\Filament\Tenant\Widgets\LoanInsightsWidget;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanEligibilityOverrideRequest;
use App\Models\Tenant\Member;
use App\Services\Loans\LoanDelinquencyService;
use App\Services\Loans\LoanEmiCollectionCatalogService;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
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
     * @return list<string>
     */
    public static function listTabKeys(): array
    {
        $tabs = ['emi_collect', 'emi_collected', 'portfolio', 'overdue_installments', 'guarantor_exposure'];

        if (LoanEligibilityOverrideRequest::isTableReady()) {
            $tabs[] = 'eligibility_reviews';
        }

        return $tabs;
    }

    public static function listTabLabel(string $tab): string
    {
        return match ($tab) {
            'emi_collect' => __('EMI collection'),
            'emi_collected' => __('EMI collected'),
            'overdue_installments' => __('Overdue installments'),
            'guarantor_exposure' => __('Guarantor exposure'),
            'eligibility_reviews' => __('Eligibility reviews'),
            default => __('Loans'),
        };
    }

    public static function pendingEmiCollectionMemberCount(): int
    {
        $catalog = app(LoanEmiCollectionCatalogService::class);
        [$month, $year] = $catalog->currentOpenPeriod();

        return $catalog->pendingMemberCount($month, $year);
    }

    public static function listTabUrl(string $tab): string
    {
        return static::listUrl($tab);
    }

    /**
     * @param  array<string, array<string, mixed>>  $filters
     */
    public static function listUrl(string $tab = 'portfolio', array $filters = []): string
    {
        $parameters = [];

        if ($tab !== 'portfolio') {
            $parameters['tab'] = $tab;
        }

        if ($filters !== []) {
            $parameters['filters'] = $filters;
        }

        return static::getUrl('index', $parameters, panel: 'tenant');
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
        return static::getUrl('queue', ['tab' => $tab]);
    }

    public static function overdueInstallmentsUrlForMember(int|Member $member): string
    {
        return static::listUrl('overdue_installments', static::memberFilter($member));
    }

    /**
     * Must stay aligned with {@see ListLoans::getTabs()} keys and the `tab` URL query.
     */
    public static function resolveListTab(): string
    {
        $livewire = Livewire::current();

        if ($livewire instanceof ListLoans && filled($livewire->activeTab)) {
            $tab = $livewire->activeTab;
        } else {
            $tab = request()->string('tab')->toString() ?: 'portfolio';
        }

        return in_array($tab, self::listTabKeys(), true) ? $tab : 'portfolio';
    }

    public static function form(Schema $schema): Schema
    {
        return LoanForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        $livewire = Livewire::current();

        return match (self::resolveListTab()) {
            'emi_collect' => LoanEmiCollectionTables::configurePendingMembersTable($table),
            'emi_collected' => LoanEmiCollectionTables::configureCollectedTable($table),
            'overdue_installments' => LoanDelinquencyTables::configureOverdueInstallmentsTable($table),
            'guarantor_exposure' => LoanDelinquencyTables::configureGuarantorExposureTable(
                $table,
                $livewire instanceof ListLoans ? $livewire : null,
            ),
            'eligibility_reviews' => LoanEligibilityOverrideRequestsTable::configure($table),
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
            'queue' => ListLoanQueue::route('/queue'),
            'create' => CreateLoan::route('/create'),
            'view' => ViewLoan::route('/{record}'),
            'edit' => EditLoan::route('/{record}/edit'),
        ];
    }

    public static function overdueInstallmentsCount(): int
    {
        return LoanDelinquencyTables::overdueInstallmentsQuery()->count();
    }

    public static function guarantorExposureCount(): int
    {
        return (int) app(LoanDelinquencyService::class)->loansAtGuarantorRiskCount();
    }

    public static function dispatchInsightsRefresh(?Component $livewire): void
    {
        if ($livewire === null) {
            return;
        }

        $targetName = json_encode(
            app('livewire.factory')->resolveComponentName(LoanInsightsWidget::class),
            JSON_THROW_ON_ERROR
        );

        $livewire->js(
            'setTimeout(() => window.Livewire.getByName('.$targetName.').forEach(w => w.$refresh()), 0)'
        );
    }
}
