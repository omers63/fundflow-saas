<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Pages;

use App\Filament\Concerns\TranslatesPageNavigationLabel;
use App\Filament\Support\MemberTableColumns;
use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Tenant\Resources\Contributions\ContributionResource;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Services\ContributionCycleService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ContributionCyclePage extends Page implements HasTable
{
    use InteractsWithTable;
    use TranslatesPageNavigationLabel;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPathRoundedSquare;

    protected static ?string $navigationLabel = 'Contribution cycles';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.tenant.pages.contribution-cycle';

    public string $contributionPeriodTab = 'pending';

    public function setContributionTab(string $tab): void
    {
        if (!in_array($tab, ['pending', 'paid'], true)) {
            return;
        }

        $this->contributionPeriodTab = $tab;
        $this->resetTable();
    }

    protected function getTableQueryStringIdentifier(): ?string
    {
        return 'contribution_cycle_' . $this->contributionPeriodTab;
    }

    public function getTitle(): string
    {
        return __('Contribution cycles');
    }

    public function getSubheading(): ?string
    {
        $cycles = app(ContributionCycleService::class);
        [$m, $y] = $cycles->currentOpenPeriod();

        return __('Open period: :label', [
            'label' => $cycles->periodLabel($m, $y),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('allContributions')
                ->label(__('All contributions'))
                ->icon('heroicon-o-banknotes')
                ->url(ContributionResource::getUrl('index'))
                ->color('info'),
            Action::make('send_notifications')
                ->label(__('Send due notifications'))
                ->icon('heroicon-o-bell')
                ->color('warning')
                ->schema($this->periodFormSchema())
                ->fillForm(fn(): array => $this->defaultPeriod())
                ->action(function (array $data): void {
                    [$month, $year] = $this->resolvePeriodFromForm($data);
                    $count = app(ContributionCycleService::class)
                        ->sendDueNotifications($month, $year);

                    Notification::make()
                        ->title(__('Notifications sent'))
                        ->body(__(':count member(s) notified for :period', [
                            'count' => $count,
                            'period' => $this->periodLabel($month, $year),
                        ]))
                        ->success()
                        ->send();
                }),
            Action::make('run_cycle')
                ->label(__('Run contribution cycle'))
                ->icon('heroicon-o-play')
                ->color('primary')
                ->schema($this->periodFormSchema())
                ->fillForm(fn(): array => $this->defaultPeriod())
                ->action(function (array $data): void {
                    $service = app(ContributionCycleService::class);
                    [$month, $year] = $this->resolvePeriodFromForm($data);
                    $results = $service->applyContributions($month, $year);

                    Notification::make()
                        ->title(__('Cycle complete – :period', ['period' => $this->periodLabel($month, $year)]))
                        ->body(__('Applied: :applied | Insufficient: :insufficient | Skipped: :skipped', [
                            'applied' => $results['applied']->count(),
                            'insufficient' => $results['insufficient']->count(),
                            'skipped' => $results['skipped']->count(),
                        ]))
                        ->color($results['insufficient']->count() > 0 ? 'warning' : 'success')
                        ->send();
                }),
        ];
    }

    public function table(Table $table): Table
    {
        [$month, $year] = app(ContributionCycleService::class)->currentOpenPeriod();

        if ($this->contributionPeriodTab === 'paid') {
            return $this->paidTable($table, $month, $year);
        }

        return $this->pendingTable($table, $month, $year);
    }

    private function pendingTable(Table $table, int $month, int $year): Table
    {
        $currency = Setting::get('general', 'currency', 'USD');
        $cycles = app(ContributionCycleService::class);

        return $table
            ->query(fn(): Builder => $cycles->pendingMembersQueryForPeriod($month, $year))
            ->heading(__('Pending members – :period', ['period' => $this->periodLabel($month, $year)]))
            ->columns([
                MemberTableColumns::number(label: __('Member #'))
                    ->searchable()
                    ->sortable(),
                MemberTableColumns::name(label: __('Member'))
                    ->searchable()
                    ->sortable()
                    ->wrap(),
                TextColumn::make('monthly_contribution_amount')
                    ->label(__('Required'))
                    ->sortable()
                    ->money($currency),
                TextColumn::make('available_cash')
                    ->label(__('Cash balance'))
                    ->state(fn(Member $record): float => $record->getCashBalance())
                    ->money($currency)
                    ->alignEnd(),
                TextColumn::make('coverage')
                    ->label(__('Ready'))
                    ->state(function (Member $record) use ($cycles, $month, $year): string {
                        $required = $cycles->requiredCashForMemberPeriod($record, $month, $year);

                        return $record->getCashBalance() >= $required
                            ? __('Yes')
                            : __('Insufficient');
                    })
                    ->badge()
                    ->color(fn(string $state): string => $state === __('Yes') ? 'success' : 'warning'),
                TextColumn::make('parent.name')
                    ->label(__('Parent'))
                    ->placeholder(__('—'))
                    ->toggleable(),
            ])
            ->recordActions(TableRecordActionGroups::wrap([
                Action::make('apply_single')
                    ->label(__('Apply now'))
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->requiresConfirmation()
                    ->action(function (Member $record) use ($month, $year, $cycles): void {
                        $outcome = $cycles->applyContributionForMemberForPeriod($record, $month, $year);

                        Notification::make()
                            ->title(match ($outcome) {
                                'applied' => __('Contribution applied'),
                                'already_contributed' => __('Already recorded'),
                                'exempt' => __('Member exempt'),
                                default => __('Could not apply'),
                            })
                            ->body(match ($outcome) {
                                'applied' => __('Posted for :name.', ['name' => $record->name]),
                                'insufficient' => __('Insufficient cash balance.'),
                                'exempt' => __('Active loan with pending installments.'),
                                default => $outcome,
                            })
                            ->color($outcome === 'applied' ? 'success' : 'warning')
                            ->send();
                    }),
            ]))
            ->toolbarActions([
                BulkActionGroup::make([]),
            ]);
    }

    private function paidTable(Table $table, int $month, int $year): Table
    {
        $currency = Setting::get('general', 'currency', 'USD');
        $cycles = app(ContributionCycleService::class);

        return $table
            ->query(fn(): Builder => $cycles->postedContributionsQueryForPeriod($month, $year))
            ->heading(__('Paid – :period', ['period' => $this->periodLabel($month, $year)]))
            ->columns([
                MemberTableColumns::relationNumber(),
                MemberTableColumns::relationName(),
                TextColumn::make('amount')->money($currency),
                TextColumn::make('is_late')
                    ->label(__('Late'))
                    ->formatStateUsing(fn(bool $state): string => $state ? __('Yes') : __('No')),
                TextColumn::make('posted_at')->dateTime()->placeholder(__('—')),
            ])
            ->recordActions(TableRecordActionGroups::wrap([]));
    }

    /**
     * @return list<Component>
     */
    private function periodFormSchema(): array
    {
        $cycles = app(ContributionCycleService::class);
        $options = $cycles->contributionCycleSelectOptionsForBulk();

        return [
            Select::make('cycle')
                ->label(__('Period'))
                ->options($options)
                ->required()
                ->live()
                ->afterStateUpdated(function ($state, callable $set) use ($cycles): void {
                    if (!is_string($state)) {
                        return;
                    }
                    [$m, $y] = $cycles->parseContributionCycleKey($state);
                    $set('month', $m);
                    $set('year', $y);
                }),
            Hidden::make('month'),
            Hidden::make('year'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultPeriod(): array
    {
        $cycles = app(ContributionCycleService::class);
        [$m, $y] = $cycles->currentOpenPeriod();
        $key = $cycles->contributionCycleKey($m, $y);

        return ['cycle' => $key, 'month' => $m, 'year' => $y];
    }

    private function periodLabel(int $month, int $year): string
    {
        return app(ContributionCycleService::class)->periodLabel($month, $year);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: int, 1: int}
     */
    private function resolvePeriodFromForm(array $data): array
    {
        if (!empty($data['month']) && !empty($data['year'])) {
            return [(int) $data['month'], (int) $data['year']];
        }

        if (!empty($data['cycle'])) {
            return app(ContributionCycleService::class)->parseContributionCycleKey((string) $data['cycle']);
        }

        return app(ContributionCycleService::class)->currentOpenPeriod();
    }
}
