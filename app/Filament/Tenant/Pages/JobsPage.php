<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Pages;

use App\Filament\Concerns\TranslatesPageNavigationLabel;
use App\Filament\Tenant\Concerns\InteractsWithAdvancedUi;
use App\Filament\Tenant\Concerns\InteractsWithJobsTable;
use App\Filament\Tenant\Support\TenantNavigation;
use App\Services\ReconciliationService;
use App\Support\BatchPostingGate;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use UnitEnum;

class JobsPage extends Page implements HasTable
{
    use InteractsWithAdvancedUi;
    use InteractsWithJobsTable;
    use InteractsWithTable;
    use TranslatesPageNavigationLabel;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCpuChip;

    protected static string|UnitEnum|null $navigationGroup = TenantNavigation::GROUP_SYSTEM;

    protected static ?string $navigationLabel = 'Automation';

    protected static ?string $slug = 'jobs';

    protected static ?int $navigationSort = TenantNavigation::SORT_JOBS;

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.tenant.pages.jobs';

    public static function canAccess(): bool
    {
        return auth()->guard('tenant')->check();
    }

    public function mount(): void
    {
        $this->mountAdvancedUi();

        if (! in_array($this->jobsTab, ['status', 'catalog', 'history'], true)) {
            $this->jobsTab = 'status';
        }
    }

    protected function onAdvancedUiToggled(): void
    {
        if (! $this->advancedUi && in_array($this->jobsTab, ['catalog', 'history'], true)) {
            $this->setJobsTab('status');
        }
    }

    public function getTitle(): string
    {
        return __('Automation');
    }

    public function getSubheading(): ?string
    {
        $gate = app(BatchPostingGate::class);

        if ($gate->isHalted()) {
            return __('Batch posting halted: :reason', ['reason' => $gate->reason() ?? __('Critical reconciliation issue')]);
        }

        return __('Monitor scheduled fund operations for this tenant.');
    }

    public function batchPostingIsHalted(): bool
    {
        return app(BatchPostingGate::class)->isHalted();
    }

    public function batchPostingHaltReason(): ?string
    {
        return app(BatchPostingGate::class)->reason();
    }

    protected function getHeaderActions(): array
    {
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
                ->label(__('Open issues'))
                ->icon('heroicon-o-shield-exclamation')
                ->url(ReconciliationOverviewPage::getUrl(['sideTab' => 'exceptions'])),
            Action::make('run_reconciliation')
                ->label(__('Run reconciliation'))
                ->icon('heroicon-o-shield-check')
                ->color('primary')
                ->visible(fn (): bool => $this->advancedUi)
                ->action(function (): void {
                    $result = app(ReconciliationService::class)->runNightlyBatch();

                    Notification::make()
                        ->title($result['halted'] ? __('Reconciliation halted') : __('Reconciliation complete'))
                        ->body(__('Raised: :raised | Resolved: :resolved', [
                            'raised' => $result['raised'],
                            'resolved' => $result['resolved'],
                        ]))
                        ->color($result['halted'] ? 'danger' : 'success')
                        ->send();
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $this->configureJobsTable($table);
    }

    protected function getTableQueryStringIdentifier(): ?string
    {
        return $this->getJobsTableQueryStringIdentifier();
    }
}
