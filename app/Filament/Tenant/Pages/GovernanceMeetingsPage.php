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
use App\Models\Tenant\Meeting;
use App\Models\Tenant\Member;
use App\Models\Tenant\Motion;
use App\Models\Tenant\User;
use App\Services\Governance\MeetingMinutesPdfService;
use App\Services\Governance\MotionService;
use App\Support\GovernanceSettings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
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
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

class GovernanceMeetingsPage extends Page implements HasTable
{
    use InteractsWithTable;
    use TranslatesPageNavigationLabel;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static string|UnitEnum|null $navigationGroup = TenantNavigation::GROUP_SYSTEM;

    protected static ?string $navigationLabel = 'Meetings & motions';

    protected static ?int $navigationSort = TenantNavigation::SORT_SETTINGS - 1;

    protected static ?string $slug = 'meetings-motions';

    protected string $view = 'filament.tenant.pages.governance-meetings-page';

    public static function canAccess(): bool
    {
        return auth('tenant')->user()?->is_admin === true;
    }

    public function getTitle(): string|Htmlable
    {
        return __('Meetings & motions');
    }

    public function getSubheading(): ?string
    {
        return __('Propose decisions, open votes, and lock results after minutes are published.');
    }

    public function table(Table $table): Table
    {
        return TableGrouping::apply(
            TableRecordActionGroups::apply(
                $table
                    ->query(Motion::query()->with('meeting')->latest('id'))
                    ->columns([
                        TextColumn::make('id')->label(__('Motion'))->sortable(),
                        TextColumn::make('meeting.title')->label(__('Meeting'))->wrap(),
                        TextColumn::make('title')->wrap()->searchable(),
                        TextColumn::make('type')
                            ->badge()
                            ->formatStateUsing(fn(string $state): string => Motion::typeLabels()[$state] ?? $state),
                        TextColumn::make('status')
                            ->badge()
                            ->formatStateUsing(fn(string $state): string => Motion::statusLabels()[$state] ?? $state),
                        TextColumn::make('yes_count')->label(__('Yes'))->numeric(),
                        TextColumn::make('no_count')->label(__('No'))->numeric(),
                        TextColumn::make('quorum_met')->label(__('Quorum'))->badge()
                            ->formatStateUsing(fn($state): string => $state ? __('Met') : __('Not met')),
                        TextColumn::make('created_at')->dateTime()->sortable(),
                    ])
                    ->filters([
                        SelectFilter::make('status')->options(Motion::statusLabels()),
                        SelectFilter::make('type')->options(Motion::typeLabels()),
                        DateColumnRangeFilter::make('created_at', __('Created')),
                    ])
                    ->defaultSort('id', 'desc')
                    ->toolbarActions(TableStandards::defaultToolbarActions()),
                [
                    $this->openVotingAction(),
                    $this->grantProxyAction(),
                    $this->closeResolveAction(),
                    $this->publishMinutesAction(),
                    $this->downloadMinutesPdfAction(),
                ],
            ),
            [
                Group::make('status')
                    ->label(__('Status'))
                    ->titlePrefixedWithLabel(false),
            ],
        );
    }

    protected function getHeaderActions(): array
    {
        return [
            TableHeaderIconAction::apply(
                Action::make('createMeetingMotion')
                    ->label(__('New meeting & motion'))
                    ->icon('heroicon-o-plus-circle')
                    ->schema([
                        TextInput::make('meeting_title')->label(__('Meeting title'))->required()->maxLength(255),
                        Textarea::make('meeting_description')->label(__('Meeting description'))->rows(2),
                        TextInput::make('motion_title')->label(__('Motion title'))->required()->maxLength(255),
                        Select::make('type')->label(__('Motion type'))->options(Motion::typeLabels())->required()->default(Motion::TYPE_GENERIC),
                        Textarea::make('body')->label(__('Motion body'))->rows(3),
                        TextInput::make('amount_threshold')->label(__('Amount threshold'))->numeric()->minValue(0),
                        TextInput::make('payload_group')->label(__('Setting group (optional)')),
                        TextInput::make('payload_key')->label(__('Setting key (optional)')),
                    ])
                    ->action(function (array $data): void {
                        /** @var User $user */
                        $user = auth('tenant')->user();
                        $service = app(MotionService::class);

                        try {
                            $meeting = $service->createMeeting(
                                (string) $data['meeting_title'],
                                $user,
                                $data['meeting_description'] ?? null,
                            );

                            $payload = array_filter([
                                'group' => $data['payload_group'] ?? null,
                                'key' => $data['payload_key'] ?? null,
                            ]);

                            $motion = $service->createMotion(
                                $meeting,
                                (string) $data['motion_title'],
                                (string) $data['type'],
                                $data['body'] ?? null,
                                $payload,
                                isset($data['amount_threshold']) && $data['amount_threshold'] !== null && $data['amount_threshold'] !== ''
                                ? (float) $data['amount_threshold']
                                : null,
                                $user,
                            );
                        } catch (InvalidArgumentException $e) {
                            Notification::make()->title(__('Could not create'))->body($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()
                            ->title(__('Meeting created'))
                            ->body(__('Motion #:id is ready to open for voting.', ['id' => $motion->id]))
                            ->success()
                            ->send();
                    }),
            ),
            TableHeaderIconAction::apply(
                Action::make('governanceSettings')
                    ->label(__('Governance settings'))
                    ->icon('heroicon-o-cog-6-tooth')
                    ->fillForm(fn(): array => [
                        'quorum_percent' => GovernanceSettings::quorumPercent(),
                        'board_only_voting' => GovernanceSettings::boardOnlyVoting(),
                        'expense_motion_threshold' => GovernanceSettings::expenseMotionThreshold(),
                        'setting_change_requires_motion' => GovernanceSettings::settingChangeRequiresMotion(),
                        'protected_setting_groups' => implode(',', GovernanceSettings::protectedSettingGroups()),
                        'distribution_motion_threshold' => GovernanceSettings::distributionMotionThreshold(),
                    ])
                    ->schema([
                        TextInput::make('quorum_percent')->label(__('Quorum percent'))->numeric()->required()->minValue(1)->maxValue(100),
                        Toggle::make('board_only_voting')->label(__('Board-only voting')),
                        TextInput::make('expense_motion_threshold')->label(__('Expense motion threshold'))->numeric()->required()->minValue(0),
                        Toggle::make('setting_change_requires_motion')->label(__('Require motion for protected settings')),
                        TextInput::make('protected_setting_groups')->label(__('Protected setting groups'))->helperText(__('Comma-separated group names')),
                        TextInput::make('distribution_motion_threshold')->label(__('Distribution motion threshold'))->numeric()->required()->minValue(0),
                    ])
                    ->action(function (array $data): void {
                        GovernanceSettings::save($data);
                        Notification::make()->title(__('Governance settings saved'))->success()->send();
                    }),
            ),
        ];
    }

    private function openVotingAction(): Action
    {
        return Action::make('openVoting')
            ->label(__('Open voting'))
            ->icon('heroicon-o-lock-open')
            ->visible(fn(Motion $record): bool => $record->status === Motion::STATUS_DRAFT)
            ->schema([
                DateTimePicker::make('voting_closes_at')->label(__('Voting closes at'))->native(false),
            ])
            ->action(function (Motion $record, array $data): void {
                try {
                    app(MotionService::class)->openMotionForVoting(
                        $record,
                        filled($data['voting_closes_at'] ?? null) ? $data['voting_closes_at'] : null,
                    );
                } catch (InvalidArgumentException $e) {
                    Notification::make()->title(__('Could not open'))->body($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title(__('Voting opened'))->success()->send();
            });
    }

    private function grantProxyAction(): Action
    {
        return Action::make('grantProxy')
            ->label(__('Grant proxy'))
            ->icon(Heroicon::OutlinedUserPlus)
            ->visible(fn(Motion $record): bool => in_array($record->status, [
                Motion::STATUS_DRAFT,
                Motion::STATUS_OPEN,
            ], true))
            ->schema([
                Select::make('grantor_member_id')
                    ->label(__('Grantor'))
                    ->options(fn() => Member::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->required(),
                Select::make('proxy_member_id')
                    ->label(__('Proxy voter'))
                    ->options(fn() => Member::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->required(),
            ])
            ->action(function (Motion $record, array $data): void {
                try {
                    app(MotionService::class)->grantProxy(
                        $record,
                        Member::query()->findOrFail((int) $data['grantor_member_id']),
                        Member::query()->findOrFail((int) $data['proxy_member_id']),
                    );
                } catch (InvalidArgumentException $e) {
                    Notification::make()->title(__('Could not grant proxy'))->body($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title(__('Proxy granted'))->success()->send();
            });
    }

    private function closeResolveAction(): Action
    {
        return Action::make('closeResolve')
            ->label(__('Close & resolve'))
            ->icon('heroicon-o-check-badge')
            ->color('success')
            ->visible(fn(Motion $record): bool => $record->status === Motion::STATUS_OPEN)
            ->requiresConfirmation()
            ->action(function (Motion $record): void {
                try {
                    $resolved = app(MotionService::class)->closeAndResolve($record);
                } catch (InvalidArgumentException $e) {
                    Notification::make()->title(__('Could not close'))->body($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()
                    ->title($resolved->status === Motion::STATUS_APPROVED ? __('Motion approved') : __('Motion rejected'))
                    ->success()
                    ->send();
            });
    }

    private function publishMinutesAction(): Action
    {
        return Action::make('publishMinutes')
            ->label(__('Publish minutes'))
            ->icon(Heroicon::OutlinedDocumentText)
            ->visible(fn(Motion $record): bool => in_array($record->meeting?->status, [
                Meeting::STATUS_OPEN,
                Meeting::STATUS_CLOSED,
            ], true) && $record->status !== Motion::STATUS_OPEN)
            ->schema([
                Textarea::make('minutes')->label(__('Minutes'))->required()->rows(6),
            ])
            ->action(function (Motion $record, array $data): void {
                $meeting = $record->meeting;
                if ($meeting === null) {
                    return;
                }

                try {
                    app(MotionService::class)->publishMinutes($meeting, (string) $data['minutes']);
                } catch (InvalidArgumentException $e) {
                    Notification::make()->title(__('Could not publish'))->body($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title(__('Minutes published'))->success()->send();
                $this->resetTable();
            });
    }

    private function downloadMinutesPdfAction(): Action
    {
        return Action::make('downloadMinutesPdf')
            ->label(__('Minutes PDF'))
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->visible(fn(Motion $record): bool => $record->meeting?->status === Meeting::STATUS_MINUTES_PUBLISHED)
            ->action(function (Motion $record): ?StreamedResponse {
                $meeting = $record->meeting;
                if ($meeting === null) {
                    return null;
                }

                $pdf = app(MeetingMinutesPdfService::class)->make($meeting);

                return response()->streamDownload(
                    static fn() => print ($pdf->output()),
                    'meeting-' . $meeting->id . '-minutes.pdf',
                );
            });
    }
}
