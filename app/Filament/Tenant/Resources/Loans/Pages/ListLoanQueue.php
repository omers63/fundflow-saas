<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\Loans\Pages;

use App\Filament\Support\LoanFilamentActions;
use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Tenant\Resources\Loans\LoanResource;
use App\Filament\Tenant\Widgets\LoanInsightsWidget;
use App\Models\Tenant\FundTier;
use App\Models\Tenant\Loan;
use App\Models\Tenant\Setting;
use App\Services\Loans\LoanQueueOrderingService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;

class ListLoanQueue extends ListRecords
{
    protected static string $resource = LoanResource::class;

    protected static ?string $navigationLabel = 'Loan queue';

    protected static ?string $title = 'Loan queue';

    protected static ?string $slug = 'queue';

    protected static bool $shouldRegisterNavigation = false;

    public function getTitle(): string|Htmlable
    {
        return __('Loan queue');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('Review applications, disburse approved loans, and record bank payouts.');
    }

    protected function getHeaderWidgets(): array
    {
        return [
            LoanInsightsWidget::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    /**
     * @return array<string, mixed>
     */
    public function getWidgetData(): array
    {
        return [
            ...parent::getWidgetData(),
            'context' => 'queue',
            'queueTab' => $this->activeTab ?? 'needs_decision',
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('resequence')
                ->label(__('Resequence queues'))
                ->icon('heroicon-o-arrows-up-down')
                ->requiresConfirmation()
                ->action(function (): void {
                    foreach (FundTier::query()->where('is_active', true)->pluck('id') as $tierId) {
                        LoanQueueOrderingService::resequenceFundTier((int) $tierId);
                    }
                    Notification::make()
                        ->title(__('Queues resequenced'))
                        ->success()
                        ->send();
                }),
            Action::make('allLoans')
                ->label(__('All loans'))
                ->icon('heroicon-o-document-text')
                ->url(LoanResource::getUrl('index')),
        ];
    }

    public function getTabs(): array
    {
        return [
            'needs_decision' => Tab::make(__('Needs decision'))
                ->badge((string) Loan::query()->needsDecision()->count())
                ->badgeColor('warning'),
            'ready_to_disburse' => Tab::make(__('Ready to disburse'))
                ->badge((string) Loan::query()->readyToDisburse()->count())
                ->badgeColor('info'),
            'awaiting_payout' => Tab::make(__('Awaiting bank payout'))
                ->badge((string) Loan::query()->awaitingBankPayout()->count())
                ->badgeColor('gray'),
        ];
    }

    protected function getTableQuery(): Builder
    {
        $query = Loan::query()
            ->with(['member', 'loanTier', 'fundTier'])
            ->orderBy('queue_position')
            ->orderBy('applied_at');

        return match ($this->activeTab ?? 'needs_decision') {
            'ready_to_disburse' => $query->readyToDisburse(),
            'awaiting_payout' => $query->awaitingBankPayout(),
            default => $query->needsDecision(),
        };
    }

    public function table(Table $table): Table
    {
        $currency = Setting::get('general', 'currency', 'USD');

        return TableGrouping::apply($table
            ->columnManager(true)
            ->columns([
                TextColumn::make('queue_position')
                    ->label(__('Queue #'))
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('applied_at')
                    ->label(__('Applied'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('member.name')
                    ->label(__('Member'))
                    ->searchable()
                    ->wrap(),
                TextColumn::make('amount_requested')
                    ->label(__('Requested'))
                    ->money($currency)
                    ->sortable(),
                TextColumn::make('amount_approved')
                    ->label(__('Approved'))
                    ->money($currency)
                    ->placeholder('—'),
                TextColumn::make('amount_disbursed')
                    ->label(__('Disbursed'))
                    ->money($currency),
                TextColumn::make('fundTier.label')
                    ->label(__('Fund tier'))
                    ->placeholder('—'),
                TextColumn::make('is_emergency')
                    ->label(__('Emergency'))
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? __('Yes') : __('No'))
                    ->color(fn (bool $state): string => $state ? 'danger' : 'gray'),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Loan::statusOptions()[$state] ?? $state)
                    ->color(fn (string $state): string => Loan::statusColor($state)),
            ])
            ->recordActions(TableRecordActionGroups::wrap(LoanFilamentActions::workflowActions()))
            ->toolbarActions([
                BulkActionGroup::make(LoanFilamentActions::bulkActions()),
            ])
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(25), TableGrouping::loanQueue());
    }
}
