<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Pages;

use App\Filament\Concerns\TranslatesPageNavigationLabel;
use App\Filament\Tenant\Concerns\InteractsWithJobsTable;
use App\Filament\Tenant\Resources\FundAuditLogs\Tables\FundAuditLogsTable;
use App\Filament\Tenant\Resources\NotificationLogs\Tables\NotificationLogsTable;
use App\Filament\Tenant\Support\TenantNavigation;
use App\Models\Tenant\FundAuditLog;
use App\Models\Tenant\NotificationLog;
use App\Models\Tenant\ReconciliationException;
use App\Support\BatchPostingGate;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Url;
use UnitEnum;

class AuditSystemPage extends Page implements HasTable
{
    use InteractsWithJobsTable;
    use InteractsWithTable;
    use TranslatesPageNavigationLabel;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServerStack;

    protected static ?string $navigationLabel = 'Audit & System';

    protected static ?string $slug = 'audit-system';

    protected static string|UnitEnum|null $navigationGroup = TenantNavigation::GROUP_SYSTEM;

    protected static ?int $navigationSort = TenantNavigation::SORT_AUDIT_SYSTEM;

    protected string $view = 'filament.tenant.pages.audit-system';

    /** @var 'audit'|'notifications'|'jobs'|'maintenance'|'migration'|'fiscal' */
    #[Url]
    public string $sideTab = 'audit';

    /** @var 'all'|'admin'|'overrides'|'recon'|'loans' */
    #[Url(as: 'auditFilter')]
    public string $auditFilter = 'all';

    public static function canAccess(): bool
    {
        return auth('tenant')->check();
    }

    public static function getNavigationBadge(): ?string
    {
        if (! Schema::hasTable('reconciliation_exceptions')) {
            return null;
        }

        try {
            $count = ReconciliationException::query()->open()->count();

            return $count > 0 ? (string) $count : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public function getTitle(): string
    {
        return __('Audit & System');
    }

    public function getSubheading(): ?string
    {
        return __('Audit trail, notification delivery, scheduled jobs, maintenance, migration, and year-end close.');
    }

    public function mount(): void
    {
        if (! in_array($this->sideTab, ['audit', 'notifications', 'jobs', 'maintenance', 'migration', 'fiscal'], true)) {
            $this->sideTab = 'audit';
        }

        if (in_array($this->sideTab, ['maintenance', 'migration'], true) && ! $this->tenantUserIsAdmin()) {
            $this->sideTab = 'audit';
        }

        if (! in_array($this->auditFilter, ['all', 'admin', 'overrides', 'recon', 'loans'], true)) {
            $this->auditFilter = 'all';
        }
    }

    public function tenantUserIsAdmin(): bool
    {
        return auth('tenant')->user()?->is_admin === true;
    }

    /**
     * @return array<string, array{icon: string, label: string}>
     */
    public function getSideTabOptions(): array
    {
        $tabs = [
            'audit' => ['icon' => 'heroicon-o-clipboard-document-list', 'label' => __('Audit log')],
            'notifications' => ['icon' => 'heroicon-o-bell', 'label' => __('Notification log')],
            'jobs' => ['icon' => 'heroicon-o-cpu-chip', 'label' => __('Jobs')],
            'maintenance' => ['icon' => 'heroicon-o-wrench-screwdriver', 'label' => __('Maintenance')],
            'migration' => ['icon' => 'heroicon-o-arrow-path', 'label' => __('Migration')],
            'fiscal' => ['icon' => 'heroicon-o-calendar-days', 'label' => __('Year-end close')],
        ];

        if (! $this->tenantUserIsAdmin()) {
            unset($tabs['maintenance'], $tabs['migration']);
        }

        return $tabs;
    }

    public function setAuditFilter(string $filter): void
    {
        if (! in_array($filter, ['all', 'admin', 'overrides', 'recon', 'loans'], true)) {
            return;
        }

        $this->auditFilter = $filter;
        $this->resetTable();
    }

    public function updatedSideTab(?string $value): void
    {
        if (in_array($value, ['audit', 'notifications', 'jobs'], true)) {
            $this->resetTable();
        }
    }

    public function table(Table $table): Table
    {
        return match ($this->sideTab) {
            'notifications' => NotificationLogsTable::configure(
                $table->query(NotificationLog::query())
            ),
            'jobs' => $this->configureJobsTable($table),
            default => FundAuditLogsTable::configure(
                $table->query($this->auditLogQuery())
            ),
        };
    }

    protected function auditLogQuery(): Builder
    {
        $query = FundAuditLog::query();

        return match ($this->auditFilter) {
            'admin' => $query->whereIn('domain', ['ledger', 'contribution', 'loan', 'migration']),
            'overrides' => $query->where('event_type', 'like', '%OVERRIDE%'),
            'recon' => $query->where('domain', 'reconciliation'),
            'loans' => $query->where('domain', 'loan'),
            default => $query,
        };
    }

    protected function getHeaderActions(): array
    {
        if ($this->sideTab !== 'jobs') {
            return [];
        }

        $gate = app(BatchPostingGate::class);

        return [
            Action::make('clear_halt')
                ->label(__('Clear posting halt'))
                ->icon('heroicon-o-play')
                ->color('warning')
                ->visible($gate->isHalted())
                ->requiresConfirmation()
                ->action(function () use ($gate): void {
                    $gate->clear();
                    Notification::make()->title(__('Batch posting halt cleared'))->success()->send();
                }),
            Action::make('open_reconciliation')
                ->label(__('Reconciliation queue'))
                ->icon('heroicon-o-shield-exclamation')
                ->url(ReconciliationOverviewPage::getUrl(['sideTab' => 'exceptions'])),
        ];
    }

    protected function getTableQueryStringIdentifier(): ?string
    {
        if ($this->sideTab === 'jobs') {
            return $this->getJobsTableQueryStringIdentifier();
        }

        return 'audit_system_'.$this->sideTab.'_'.$this->auditFilter;
    }

    /**
     * @return array<string, string>
     */
    public function getAuditFilterOptions(): array
    {
        return [
            'all' => __('All'),
            'admin' => __('Admin actions'),
            'overrides' => __('Overrides'),
            'recon' => __('Recon events'),
            'loans' => __('Loan events'),
        ];
    }
}
