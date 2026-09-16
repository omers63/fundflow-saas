<?php

declare(strict_types=1);

namespace App\Filament\Member\Pages;

use App\Filament\Concerns\TranslatesPageNavigationLabel;
use App\Filament\Member\Support\MemberNavigation;
use App\Filament\Pages\Page;
use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Support\TableStandards;
use App\Models\Tenant\Member;
use App\Models\Tenant\Motion;
use App\Models\Tenant\MotionProxyGrant;
use App\Models\Tenant\Vote;
use App\Services\Governance\MotionService;
use App\Support\Tenant\CurrentMember;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
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

class MemberMotionsPage extends Page implements HasTable
{
    use InteractsWithTable;
    use TranslatesPageNavigationLabel;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHandRaised;

    protected static string|UnitEnum|null $navigationGroup = MemberNavigation::GROUP_SELF_SERVICE;

    protected static ?string $navigationLabel = 'Motions';

    protected static ?int $navigationSort = MemberNavigation::SORT_REQUESTS;

    protected static ?string $slug = 'motions';

    protected string $view = 'filament.member.pages.member-motions-page';

    public static function canAccess(): bool
    {
        return CurrentMember::get() !== null;
    }

    public function getTitle(): string|Htmlable
    {
        return __('Motions');
    }

    public function getSubheading(): ?string
    {
        return __('Vote on open fund decisions.');
    }

    public function table(Table $table): Table
    {
        return TableGrouping::apply(
            TableRecordActionGroups::apply(
                $table
                    ->query(
                        Motion::query()
                            ->whereIn('status', [Motion::STATUS_OPEN, Motion::STATUS_APPROVED, Motion::STATUS_REJECTED])
                            ->with('meeting')
                            ->latest('id')
                    )
                    ->columns([
                        TextColumn::make('title')->wrap()->searchable(),
                        TextColumn::make('meeting.title')->label(__('Meeting'))->wrap(),
                        TextColumn::make('type')
                            ->badge()
                            ->formatStateUsing(fn(string $state): string => Motion::typeLabels()[$state] ?? $state),
                        TextColumn::make('status')
                            ->badge()
                            ->formatStateUsing(fn(string $state): string => Motion::statusLabels()[$state] ?? $state),
                        TextColumn::make('voting_closes_at')->label(__('Closes'))->dateTime()->placeholder(__('—')),
                    ])
                    ->filters([
                        SelectFilter::make('status')->options([
                            Motion::STATUS_OPEN => __('Open for voting'),
                            Motion::STATUS_APPROVED => __('Approved'),
                            Motion::STATUS_REJECTED => __('Rejected'),
                        ]),
                    ])
                    ->defaultSort('id', 'desc')
                    ->toolbarActions(TableStandards::defaultToolbarActions()),
                [
                    $this->voteAction(),
                    $this->grantOwnProxyAction(),
                    $this->castProxyVoteAction(),
                ],
            ),
            [
                Group::make('status')->label(__('Status'))->titlePrefixedWithLabel(false),
            ],
        );
    }

    private function voteAction(): Action
    {
        return Action::make('castVote')
            ->label(__('Vote'))
            ->icon('heroicon-o-hand-raised')
            ->visible(fn(Motion $record): bool => $record->isOpenForVoting())
            ->schema([
                Radio::make('choice')
                    ->label(__('Your vote'))
                    ->options(Vote::choiceLabels())
                    ->required(),
            ])
            ->action(function (Motion $record, array $data): void {
                $member = CurrentMember::get();

                if ($member === null) {
                    return;
                }

                try {
                    app(MotionService::class)->castVote($record, $member, (string) $data['choice']);
                } catch (InvalidArgumentException $e) {
                    Notification::make()->title(__('Could not vote'))->body($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title(__('Vote recorded'))->success()->send();
            });
    }

    private function grantOwnProxyAction(): Action
    {
        return Action::make('grantOwnProxy')
            ->label(__('Assign proxy'))
            ->icon(Heroicon::OutlinedUserPlus)
            ->visible(fn(Motion $record): bool => $record->isOpenForVoting() || $record->status === Motion::STATUS_DRAFT)
            ->schema([
                Select::make('proxy_member_id')
                    ->label(__('Proxy member'))
                    ->options(function (): array {
                        $self = CurrentMember::get();

                        return Member::query()
                            ->where('status', 'active')
                            ->when($self !== null, fn($q) => $q->where('id', '!=', $self->id))
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all();
                    })
                    ->searchable()
                    ->required(),
            ])
            ->action(function (Motion $record, array $data): void {
                $grantor = CurrentMember::get();
                if ($grantor === null) {
                    return;
                }

                try {
                    app(MotionService::class)->grantProxy(
                        $record,
                        $grantor,
                        Member::query()->findOrFail((int) $data['proxy_member_id']),
                    );
                } catch (InvalidArgumentException $e) {
                    Notification::make()->title(__('Could not assign proxy'))->body($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title(__('Proxy assigned'))->success()->send();
            });
    }

    private function castProxyVoteAction(): Action
    {
        return Action::make('castProxyVote')
            ->label(__('Vote as proxy'))
            ->icon(Heroicon::OutlinedUsers)
            ->visible(function (Motion $record): bool {
                $proxy = CurrentMember::get();
                if ($proxy === null || !$record->isOpenForVoting()) {
                    return false;
                }

                return MotionProxyGrant::query()
                    ->where('motion_id', $record->id)
                    ->where('proxy_member_id', $proxy->id)
                    ->whereNull('revoked_at')
                    ->exists();
            })
            ->schema([
                Select::make('grantor_member_id')
                    ->label(__('Voting for'))
                    ->options(function (Motion $record): array {
                        $proxy = CurrentMember::get();
                        if ($proxy === null) {
                            return [];
                        }

                        return MotionProxyGrant::query()
                            ->where('motion_id', $record->id)
                            ->where('proxy_member_id', $proxy->id)
                            ->whereNull('revoked_at')
                            ->with('grantor')
                            ->get()
                            ->mapWithKeys(fn(MotionProxyGrant $grant): array => [
                                $grant->grantor_member_id => $grant->grantor?->name ?? (string) $grant->grantor_member_id,
                            ])
                            ->all();
                    })
                    ->required(),
                Radio::make('choice')
                    ->label(__('Vote'))
                    ->options(Vote::choiceLabels())
                    ->required(),
                Textarea::make('note')->label(__('Note'))->rows(2),
            ])
            ->action(function (Motion $record, array $data): void {
                $proxy = CurrentMember::get();
                if ($proxy === null) {
                    return;
                }

                try {
                    app(MotionService::class)->castProxyVote(
                        $record,
                        $proxy,
                        Member::query()->findOrFail((int) $data['grantor_member_id']),
                        (string) $data['choice'],
                        $data['note'] ?? null,
                    );
                } catch (InvalidArgumentException $e) {
                    Notification::make()->title(__('Could not cast proxy vote'))->body($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title(__('Proxy vote recorded'))->success()->send();
            });
    }
}
