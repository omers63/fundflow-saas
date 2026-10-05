<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Pages;

use App\Filament\Concerns\TranslatesPageNavigationLabel;
use App\Filament\Pages\Page;
use App\Filament\Support\DateColumnRangeFilter;
use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableHeaderIconAction;
use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Support\TableStandards;
use App\Filament\Tenant\Support\TenantNavigation;
use App\Models\Tenant\Motion;
use App\Models\Tenant\ProfitDistribution;
use App\Models\Tenant\Setting;
use App\Models\Tenant\User;
use App\Services\ProfitDistribution\ProfitDistributionService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use InvalidArgumentException;
use UnitEnum;

class ProfitDistributionsPage extends Page implements HasTable
{
    use InteractsWithTable;
    use TranslatesPageNavigationLabel;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartPie;

    protected static string|UnitEnum|null $navigationGroup = TenantNavigation::GROUP_ACCOUNTS;

    protected static ?string $navigationLabel = 'Profit distributions';

    protected static ?int $navigationSort = TenantNavigation::SORT_REPORTS - 1;

    protected static ?string $slug = 'profit-distributions';

    protected string $view = 'filament.tenant.pages.profit-distributions-page';

    public static function canAccess(): bool
    {
        return auth('tenant')->user()?->is_admin === true;
    }

    public function getTitle(): string|Htmlable
    {
        return __('Profit distributions');
    }

    public function getSubheading(): ?string
    {
        return __('Preview, approve, post, and reverse time-weighted fund return distributions.');
    }

    public function table(Table $table): Table
    {
        return TableGrouping::apply(
            TableRecordActionGroups::apply(
                $table
                    ->query(ProfitDistribution::query()->latest('id'))
                    ->columns([
                        TextColumn::make('id')->label(__('Run'))->sortable(),
                        TextColumn::make('status')
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => ProfitDistribution::statusLabels()[$state] ?? $state),
                        TextColumn::make('period_start')->date()->sortable(),
                        TextColumn::make('period_end')->date()->sortable(),
                        TextColumn::make('amount')
                            ->money(fn (): string => Setting::get('general', 'currency', 'SAR'))
                            ->sortable(),
                        TextColumn::make('item_count')->label(__('Members'))->numeric(),
                        TextColumn::make('posted_total')
                            ->money(fn (): string => Setting::get('general', 'currency', 'SAR')),
                        TextColumn::make('motion_id')->label(__('Motion'))->placeholder(__('—')),
                        TextColumn::make('created_at')->dateTime()->sortable(),
                    ])
                    ->filters([
                        SelectFilter::make('status')->options(ProfitDistribution::statusLabels()),
                        DateColumnRangeFilter::make('period_start', __('Period start')),
                        DateColumnRangeFilter::make('created_at', __('Created')),
                    ])
                    ->defaultSort('id', 'desc')
                    ->toolbarActions(TableStandards::defaultToolbarActions()),
                [
                    $this->approveAction(),
                    $this->postAction(),
                    $this->reverseAction(),
                ],
            ),
            [
                Group::make('status')->label(__('Status'))->titlePrefixedWithLabel(false),
            ],
        );
    }

    protected function getHeaderActions(): array
    {
        return [
            TableHeaderIconAction::apply(
                Action::make('createDistribution')
                    ->label(__('New distribution'))
                    ->icon('heroicon-o-plus-circle')
                    ->schema([
                        DatePicker::make('period_start')->label(__('Period start'))->required()->native(false),
                        DatePicker::make('period_end')->label(__('Period end'))->required()->native(false),
                        TextInput::make('amount')->label(__('Amount'))->numeric()->required()->minValue(0.01),
                        Select::make('motion_id')
                            ->label(__('Approved motion'))
                            ->options(
                                fn (): array => Motion::query()
                                    ->where('status', Motion::STATUS_APPROVED)
                                    ->whereIn('type', [Motion::TYPE_DISTRIBUTION_RUN, Motion::TYPE_GENERIC])
                                    ->orderByDesc('id')
                                    ->pluck('title', 'id')
                                    ->all()
                            )
                            ->searchable()
                            ->helperText(__('Required when amount exceeds the distribution motion threshold.')),
                        Textarea::make('notes')->label(__('Notes'))->rows(2),
                    ])
                    ->action(function (array $data): void {
                        /** @var User $user */
                        $user = auth('tenant')->user();

                        try {
                            $run = app(ProfitDistributionService::class)->createDraft(
                                (string) $data['period_start'],
                                (string) $data['period_end'],
                                (float) $data['amount'],
                                $user,
                                filled($data['motion_id'] ?? null) ? (int) $data['motion_id'] : null,
                                $data['notes'] ?? null,
                            );
                        } catch (InvalidArgumentException $e) {
                            Notification::make()->title(__('Could not create'))->body($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()
                            ->title(__('Distribution draft ready'))
                            ->body(__('Run #:id allocated across :count members.', [
                                'id' => $run->id,
                                'count' => $run->item_count,
                            ]))
                            ->success()
                            ->send();
                    }),
            ),
        ];
    }

    private function approveAction(): Action
    {
        return Action::make('approveDistribution')
            ->label(__('Approve'))
            ->icon('heroicon-o-check-badge')
            ->visible(fn (ProfitDistribution $record): bool => $record->status === ProfitDistribution::STATUS_DRAFT)
            ->requiresConfirmation()
            ->action(function (ProfitDistribution $record): void {
                /** @var User $user */
                $user = auth('tenant')->user();

                try {
                    app(ProfitDistributionService::class)->approve($record, $user);
                } catch (InvalidArgumentException $e) {
                    Notification::make()->title(__('Could not approve'))->body($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title(__('Distribution approved'))->success()->send();
            });
    }

    private function postAction(): Action
    {
        return Action::make('postDistribution')
            ->label(__('Post'))
            ->icon('heroicon-o-banknotes')
            ->color('success')
            ->visible(fn (ProfitDistribution $record): bool => $record->status === ProfitDistribution::STATUS_APPROVED)
            ->requiresConfirmation()
            ->action(function (ProfitDistribution $record): void {
                try {
                    app(ProfitDistributionService::class)->post($record);
                } catch (InvalidArgumentException $e) {
                    Notification::make()->title(__('Could not post'))->body($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title(__('Distribution posted'))->success()->send();
            });
    }

    private function reverseAction(): Action
    {
        return Action::make('reverseDistribution')
            ->label(__('Reverse'))
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('danger')
            ->visible(fn (ProfitDistribution $record): bool => $record->status === ProfitDistribution::STATUS_POSTED)
            ->requiresConfirmation()
            ->action(function (ProfitDistribution $record): void {
                try {
                    app(ProfitDistributionService::class)->reverse($record);
                } catch (InvalidArgumentException $e) {
                    Notification::make()->title(__('Could not reverse'))->body($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title(__('Distribution reversed'))->success()->send();
            });
    }
}
