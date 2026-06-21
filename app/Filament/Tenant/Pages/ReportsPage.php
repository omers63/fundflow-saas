<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Pages;

use App\Filament\Concerns\TranslatesPageNavigationLabel;
use App\Filament\Tenant\Resources\Contributions\ContributionResource;
use App\Filament\Tenant\Resources\FundTiers\FundTierResource;
use App\Filament\Tenant\Resources\Loans\LoanResource;
use App\Filament\Tenant\Resources\MonthlyStatements\MonthlyStatementResource;
use App\Filament\Tenant\Support\TenantNavigation;
use App\Services\Tenant\TenantAdminReportExportService;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class ReportsPage extends Page
{
    use TranslatesPageNavigationLabel;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBarSquare;

    protected static string|UnitEnum|null $navigationGroup = TenantNavigation::GROUP_ACCOUNTS;

    protected static ?string $navigationLabel = 'Reports';

    protected static ?int $navigationSort = TenantNavigation::SORT_REPORTS;

    protected static ?string $slug = 'reports';

    protected static ?string $title = 'Reports';

    protected string $view = 'filament.tenant.pages.reports';

    public string $reportType = 'collections';

    public ?string $reportFrom = null;

    public ?string $reportUntil = null;

    public string $reportFormat = 'pdf';

    public static function canAccess(): bool
    {
        return auth()->guard('tenant')->check();
    }

    public function getTitle(): string
    {
        return __('Reports');
    }

    public function getSubheading(): ?string
    {
        return __('Standard exports and shortcuts to portfolio, collection, and reconciliation views.');
    }

    public function generateCustomReport(): mixed
    {
        try {
            return app(TenantAdminReportExportService::class)->download(
                type: $this->reportType,
                format: $this->reportFormat,
                from: $this->reportFrom,
                until: $this->reportUntil,
            );
        } catch (\InvalidArgumentException $exception) {
            Notification::make()
                ->title(__('Report export failed'))
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return null;
        }
    }

    /**
     * @return list<array{title: string, description: string, icon: string, url: string|null, badge: string|null}>
     */
    public function reportCards(): array
    {
        return [
            [
                'title' => __('Monthly collection report'),
                'description' => __('Open the collections workspace for the active cycle and posted history.'),
                'icon' => 'heroicon-o-currency-dollar',
                'url' => ContributionResource::getUrl('index'),
                'badge' => null,
            ],
            [
                'title' => __('Loan portfolio report'),
                'description' => __('Review active, repaid, and delinquent loans across the portfolio.'),
                'icon' => 'heroicon-o-document-text',
                'url' => LoanResource::listUrl('portfolio'),
                'badge' => null,
            ],
            [
                'title' => __('Reconciliation summary'),
                'description' => __('Exception queue, snapshots, and downloadable reconciliation reports.'),
                'icon' => 'heroicon-o-scale',
                'url' => ReconciliationOverviewPage::getUrl(),
                'badge' => null,
            ],
            [
                'title' => __('Fund tier utilisation'),
                'description' => __('Inspect committed and available capacity by fund tier.'),
                'icon' => 'heroicon-o-chart-pie',
                'url' => FundTierResource::getUrl('index'),
                'badge' => null,
            ],
            [
                'title' => __('Member statements (bulk)'),
                'description' => __('Generate and notify monthly member statements.'),
                'icon' => 'heroicon-o-document-chart-bar',
                'url' => MonthlyStatementResource::getUrl('index'),
                'badge' => null,
            ],
            [
                'title' => __('Guarantor exposure report'),
                'description' => __('Export guarantor exposure or open the delinquency guarantor tab.'),
                'icon' => 'heroicon-o-shield-check',
                'url' => LoanResource::listUrl('guarantor_exposure'),
                'badge' => null,
            ],
            [
                'title' => __('Audit trail export'),
                'description' => __('Fund audit logs and system maintenance exports.'),
                'icon' => 'heroicon-o-clipboard-document-list',
                'url' => AuditSystemPage::getUrl(['sideTab' => 'audit']),
                'badge' => null,
            ],
        ];
    }

    /**
     * @return array<string>
     */
    public function getPageClasses(): array
    {
        return ['fi-page-reports'];
    }
}
