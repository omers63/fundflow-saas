<?php

namespace App\Filament\Tenant\Resources\Loans;

use App\Filament\Concerns\TranslatesFilamentNavigationLabels;
use App\Filament\Support\LoanDelinquencyTables;
use App\Filament\Tenant\Clusters\LoansCluster;
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
use App\Services\Loans\LoanDelinquencyService;
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
        return ['portfolio', 'overdue_installments', 'guarantor_exposure'];
    }

    public static function listTabLabel(string $tab): string
    {
        return match ($tab) {
            'overdue_installments' => __('Overdue installments'),
            'guarantor_exposure' => __('Guarantor exposure'),
            default => __('Portfolio'),
        };
    }

    public static function listTabUrl(string $tab): string
    {
        if ($tab === 'portfolio') {
            return static::getUrl('index');
        }

        return static::getUrl('index', ['tab' => $tab]);
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
            'overdue_installments' => LoanDelinquencyTables::configureOverdueInstallmentsTable($table),
            'guarantor_exposure' => LoanDelinquencyTables::configureGuarantorExposureTable(
                $table,
                $livewire instanceof ListLoans ? $livewire : null,
            ),
            default => LoansTable::configure($table),
        };
    }

    public static function getRelations(): array
    {
        return [
            InstallmentsRelationManager::class,
            DisbursementsRelationManager::class,
            RepaymentsRelationManager::class,
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
            'setTimeout(() => window.Livewire.getByName(' . $targetName . ').forEach(w => w.$refresh()), 0)'
        );
    }
}
