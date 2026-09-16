<?php

declare(strict_types=1);

namespace App\Filament\Member\Pages;

use App\Filament\Concerns\TranslatesPageNavigationLabel;
use App\Filament\Member\Support\MemberNavigation;
use App\Filament\Pages\Page;
use App\Filament\Support\TableHeaderIconAction;
use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Support\TableStandards;
use App\Models\Tenant\MemberSavingsGoal;
use App\Services\Savings\MemberSavingsGoalService;
use App\Support\Tenant\CurrentMember;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use UnitEnum;

class MemberSavingsGoalsPage extends Page implements HasTable
{
    use InteractsWithTable;
    use TranslatesPageNavigationLabel;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFlag;

    protected static string|UnitEnum|null $navigationGroup = MemberNavigation::GROUP_MY_ACCOUNTS;

    protected static ?string $navigationLabel = 'Savings goals';

    protected static ?int $navigationSort = MemberNavigation::SORT_FUND_ACCOUNT + 1;

    protected static ?string $slug = 'savings-goals';

    protected string $view = 'filament.member.pages.member-savings-goals';

    public static function canAccess(): bool
    {
        return CurrentMember::get() !== null;
    }

    public function getTitle(): string|Htmlable
    {
        return __('Savings goals');
    }

    public function getSubheading(): ?string
    {
        return __('Track progress against your live fund balance. Creating a goal does not move money.');
    }

    public function table(Table $table): Table
    {
        $member = CurrentMember::get();

        return TableRecordActionGroups::apply(
            $table
                ->query(
                    MemberSavingsGoal::query()
                        ->where('member_id', $member?->id ?? 0)
                        ->latest('id'),
                )
                ->columns([
                    TextColumn::make('title')->searchable()->wrap(),
                    TextColumn::make('target_amount')->numeric(decimalPlaces: 2)->label(__('Target')),
                    TextColumn::make('target_date')->date()->label(__('Target date')),
                    TextColumn::make('status')->badge(),
                    TextColumn::make('progress')
                        ->label(__('Progress'))
                        ->state(function (MemberSavingsGoal $record): string {
                            $progress = app(MemberSavingsGoalService::class)->progress($record);

                            return number_format($progress['progress_percent'], 1).'%';
                        }),
                    TextColumn::make('projected_hit_date')
                        ->label(__('Projected'))
                        ->state(function (MemberSavingsGoal $record): string {
                            $progress = app(MemberSavingsGoalService::class)->progress($record);

                            return $progress['projected_hit_date'] ?? '—';
                        }),
                ])
                ->filters([
                    SelectFilter::make('status')->options([
                        MemberSavingsGoal::STATUS_ACTIVE => __('Active'),
                        MemberSavingsGoal::STATUS_ACHIEVED => __('Achieved'),
                        MemberSavingsGoal::STATUS_CANCELLED => __('Cancelled'),
                    ]),
                ])
                ->toolbarActions(TableStandards::defaultToolbarActions()),
            [
                Action::make('cancel')
                    ->label(__('Cancel'))
                    ->icon(Heroicon::OutlinedXMark)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (MemberSavingsGoal $record): bool => $record->isActive())
                    ->action(function (MemberSavingsGoal $record): void {
                        $record->update(['status' => MemberSavingsGoal::STATUS_CANCELLED]);
                        $this->resetTable();
                    }),
            ],
        );
    }

    protected function getHeaderActions(): array
    {
        return [
            TableHeaderIconAction::apply(
                Action::make('create_goal')
                    ->label(__('New savings goal'))
                    ->icon(Heroicon::OutlinedPlusCircle)
                    ->schema([
                        TextInput::make('title')->label(__('Title'))->required()->maxLength(120),
                        TextInput::make('target_amount')->label(__('Target amount'))->numeric()->required()->minValue(1),
                        DatePicker::make('target_date')->label(__('Target date')),
                        Textarea::make('notes')->label(__('Notes'))->rows(2),
                    ])
                    ->action(function (array $data): void {
                        $member = CurrentMember::get();
                        if ($member === null) {
                            return;
                        }

                        app(MemberSavingsGoalService::class)->create($member, $data);

                        Notification::make()
                            ->title(__('Savings goal created'))
                            ->success()
                            ->send();

                        $this->resetTable();
                    }),
            ),
        ];
    }
}
