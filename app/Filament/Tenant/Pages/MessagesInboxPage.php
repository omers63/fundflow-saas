<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Pages;

use App\Filament\Concerns\TranslatesPageNavigationLabel;
use App\Filament\Support\MemberTableColumns;
use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Support\TableToolbar;
use App\Filament\Tenant\Concerns\HidesFromTenantSidebar;
use App\Filament\Tenant\Resources\SupportRequests\SupportRequestResource;
use App\Filament\Tenant\Support\TenantNavigation;
use App\Filament\Tenant\Support\TenantPortalViewModal;
use App\Models\Tenant\DirectMessage;
use App\Models\Tenant\Member;
use App\Models\Tenant\MemberAnnouncement;
use App\Models\Tenant\User;
use App\Services\Tenant\DirectMessagingService;
use App\Services\Tenant\MemberAnnouncementService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

class MessagesInboxPage extends Page implements HasTable
{
    use HidesFromTenantSidebar;
    use InteractsWithTable;
    use TranslatesPageNavigationLabel;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static ?string $navigationLabel = 'Messages';

    protected static ?int $navigationSort = TenantNavigation::SORT_MESSAGES;

    protected static ?string $slug = 'messages';

    protected string $view = 'filament.tenant.pages.messages-inbox';

    public static function canAccess(): bool
    {
        return auth('tenant')->user()?->is_admin === true;
    }

    public static function getNavigationBadge(): ?string
    {
        $adminId = auth('tenant')->id();

        if ($adminId === null) {
            return null;
        }

        $count = app(DirectMessagingService::class)->unreadCountForAdmin((int) $adminId);

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public function getTitle(): string
    {
        return __('Messages inbox');
    }

    protected function messaging(): DirectMessagingService
    {
        return app(DirectMessagingService::class);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('support_requests')
                ->label(__('Support requests'))
                ->icon('heroicon-o-lifebuoy')
                ->color('gray')
                ->url(SupportRequestResource::getUrl()),
            TenantPortalViewModal::applyToForm(
                Action::make('compose_announcement')
                    ->label(__('Compose announcement'))
                    ->icon('heroicon-o-megaphone')
                    ->color('primary')
                    ->modalHeading(__('Compose member announcement'))
                    ->modalDescription(__('Broadcast a bilingual message to a member audience via in-app, SMS, and/or email.'))
                    ->modalWidth('3xl')
                    ->schema($this->announcementFormSchema())
                    ->action(function (array $data): void {
                        $admin = auth('tenant')->user();

                        if (! $admin instanceof User) {
                            return;
                        }

                        try {
                            $announcement = app(MemberAnnouncementService::class)->createAndDispatch($admin, [
                                'audience' => (string) ($data['audience'] ?? MemberAnnouncement::AUDIENCE_ALL_ACTIVE),
                                'title_en' => (string) ($data['title_en'] ?? ''),
                                'title_ar' => $data['title_ar'] ?? null,
                                'body_en' => (string) ($data['body_en'] ?? ''),
                                'body_ar' => $data['body_ar'] ?? null,
                                'channels' => array_values($data['channels'] ?? []),
                                'scheduled_for' => $data['scheduled_for'] ?? null,
                            ]);
                        } catch (\InvalidArgumentException $exception) {
                            Notification::make()->title($exception->getMessage())->danger()->send();

                            return;
                        }

                        if ($announcement->scheduled_for !== null && $announcement->sent_at === null) {
                            Notification::make()
                                ->title(__('Announcement scheduled'))
                                ->body(__('Scheduled for :at', ['at' => $announcement->scheduled_for->toDayDateTimeString()]))
                                ->success()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title(__('Announcement sent'))
                            ->body(__('Delivered to :count of :total member(s).', [
                                'count' => $announcement->delivered_count,
                                'total' => $announcement->recipient_count,
                            ]))
                            ->success()
                            ->send();
                    }),
            ),
            TenantPortalViewModal::applyToForm(
                Action::make('message_all_members')
                    ->label(__('Message all members'))
                    ->icon('heroicon-o-megaphone')
                    ->color('primary')
                    ->modalHeading(__('Send message to all members'))
                    ->modalDescription(fn (): string => __('This sends the same message (and attachments) to every member who has a login account. Currently: ')
                        .Member::query()->whereNotNull('user_id')->count()
                        .' '.__('member(s).'))
                    ->modalWidth('lg')
                    ->schema($this->bulkMessageFormSchema())
                    ->action(function (array $data): void {
                        $members = Member::query()
                            ->whereNotNull('user_id')
                            ->with('user')
                            ->get();

                        $this->sendMessageToMembersCollection($members, $data);
                    }),
            ),
        ];
    }

    public function table(Table $table): Table
    {
        $adminId = (int) auth('tenant')->id();

        return TableGrouping::apply($table
            ->query(
                Member::query()
                    ->with('user')
                    ->whereNotNull('user_id')
                    ->select('members.*')
                    ->selectSub(
                        DirectMessage::query()
                            ->whereColumn('to_user_id', 'members.user_id')
                            ->whereHas('sender', fn (Builder $q): Builder => $q->where('is_admin', true))
                            ->selectRaw('count(*)'),
                        'messages_received_count'
                    )
                    ->selectSub(
                        DirectMessage::query()
                            ->whereColumn('from_user_id', 'members.user_id')
                            ->whereHas('recipient', fn (Builder $q): Builder => $q->where('is_admin', true))
                            ->selectRaw('count(*)'),
                        'messages_sent_count'
                    )
                    ->selectSub(
                        DirectMessage::query()
                            ->whereColumn('from_user_id', 'members.user_id')
                            ->where('to_user_id', $adminId)
                            ->whereNull('read_at')
                            ->selectRaw('count(*)'),
                        'unread_messages_count'
                    )
                    ->selectSub(
                        DirectMessage::query()
                            ->where(function (Builder $query): void {
                                $query->where(function (Builder $q): void {
                                    $q->whereColumn('to_user_id', 'members.user_id')
                                        ->whereHas('sender', fn (Builder $sq): Builder => $sq->where('is_admin', true));
                                })->orWhere(function (Builder $q): void {
                                    $q->whereColumn('from_user_id', 'members.user_id')
                                        ->whereHas('recipient', fn (Builder $rq): Builder => $rq->where('is_admin', true));
                                });
                            })
                            ->selectRaw('MAX(created_at)'),
                        'last_message_at'
                    )
            )
            ->defaultSort('last_message_at', 'desc')
            ->filters([
                TernaryFilter::make('has_unread')
                    ->label(__('Has unread'))
                    ->queries(
                        true: fn (Builder $query): Builder => $query->having('unread_messages_count', '>', 0),
                        false: fn (Builder $query): Builder => $query,
                        blank: fn (Builder $query): Builder => $query,
                    ),
            ])
            ->columns([
                TextColumn::make('user.name')
                    ->label(__('Member'))
                    ->wrap()
                    ->searchable()
                    ->sortable()
                    ->placeholder(__('No linked user')),
                MemberTableColumns::number(label: __('Member #'))
                    ->wrap()
                    ->searchable(),
                TextColumn::make('messages_received_count')
                    ->label(__('Received'))
                    ->badge()
                    ->color('primary'),
                TextColumn::make('messages_sent_count')
                    ->label(__('Sent'))
                    ->badge()
                    ->color('success'),
                TextColumn::make('unread_messages_count')
                    ->label(__('Unread'))
                    ->badge()
                    ->color('danger'),
                TextColumn::make('last_message_at')
                    ->label(__('Last message'))
                    ->since()
                    ->sortable()
                    ->placeholder(__('No messages yet')),
            ])
            ->recordActions(TableRecordActionGroups::wrap([
                TenantPortalViewModal::applyToForm(
                    Action::make('communicate')
                        ->label(__('Communicate'))
                        ->icon('heroicon-o-chat-bubble-left-right')
                        ->color('primary')
                        ->disabled(fn (Member $record): bool => blank($record->user_id))
                        ->modalHeading(fn (Member $record): string => __('Conversation with :name', ['name' => $record->user?->name ?? __('Member')]))
                        ->modalDescription(__('Single communication thread with full history.'))
                        ->modalWidth('5xl')
                        ->modalSubmitActionLabel(__('Send message'))
                        ->modalContent(fn (Member $record) => view(
                            'filament.tenant.pages.partials.member-conversation-modal',
                            [
                                'messages' => $this->conversationMessages($record),
                                'userId' => auth('tenant')->id(),
                            ]
                        ))
                        ->schema([
                            Textarea::make('body')
                                ->label(__('Message'))
                                ->rows(4)
                                ->required()
                                ->maxLength(3000),
                            FileUpload::make('attachments')
                                ->label(__('Attachments'))
                                ->multiple()
                                ->disk('public')
                                ->directory('direct-messages')
                                ->openable()
                                ->downloadable()
                                ->maxFiles(5),
                        ])
                        ->action(function (Action $action, Member $record, array $data): void {
                            $admin = auth('tenant')->user();

                            if (! $admin instanceof User) {
                                return;
                            }

                            $attachments = is_array($data['attachments'] ?? null) ? $data['attachments'] : [];

                            $this->messaging()->sendAdminToMember(
                                $record,
                                $admin,
                                (string) ($data['body'] ?? ''),
                                $attachments,
                            );

                            $action->data(['body' => '', 'attachments' => []], shouldMutate: false);
                            $action->halt();
                        }),
                ),
                Action::make('delete_conversation')
                    ->label(__('Delete conversation'))
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading(fn (Member $record): string => __('Delete conversation with :name?', ['name' => $record->user?->name ?? __('member')]))
                    ->modalDescription(__('This will clear all previous communications with this member from the inbox.'))
                    ->action(function (Member $record): void {
                        $this->deleteConversation($record);
                    }),
            ]))
            ->toolbarActions(TableToolbar::bulkGroup([
                BulkAction::make('send_messages')
                    ->label(__('Send message'))
                    ->icon('heroicon-o-chat-bubble-left-right')
                    ->color('info')
                    ->modalHeading(__('Send message to selected members'))
                    ->modalDescription(__('The same message and attachments are delivered to each selected member’s conversation thread.'))
                    ->modalWidth('lg')
                    ->schema($this->bulkMessageFormSchema())
                    ->action(function (array $data, EloquentCollection $records): void {
                        $members = $records->filter(
                            fn ($record): bool => $record instanceof Member && filled($record->user_id)
                        );

                        $this->sendMessageToMembersCollection($members, $data);
                    })
                    ->deselectRecordsAfterCompletion(),
                BulkAction::make('clear_conversations')
                    ->label(__('Clear conversations'))
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading(__('Clear selected conversations?'))
                    ->modalDescription(__('This will delete all previous communications for the selected member rows.'))
                    ->action(function (EloquentCollection $records): void {
                        $members = $records->filter(fn ($record): bool => $record instanceof Member);

                        $membersCleared = 0;
                        $messagesDeleted = 0;

                        foreach ($members as $member) {
                            $deletedForMember = $this->messaging()->purgeConversationForMember($member);
                            $messagesDeleted += $deletedForMember;

                            if ($deletedForMember > 0) {
                                $membersCleared++;
                            }
                        }

                        if ($messagesDeleted === 0) {
                            Notification::make()
                                ->title(__('No conversations deleted'))
                                ->warning()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title(__('Conversations cleared'))
                            ->body(__('Members').": {$membersCleared}. ".__('Messages deleted').": {$messagesDeleted}.")
                            ->success()
                            ->send();
                    })
                    ->deselectRecordsAfterCompletion(),
                TableToolbar::refreshBulkAction(),
            ]))
            ->emptyStateHeading(__('No members found'))
            ->emptyStateDescription(__('Members will appear here once they have portal accounts.')), TableGrouping::members());
    }

    /**
     * @return array<int, Select|TextInput|Textarea|CheckboxList|DateTimePicker>
     */
    protected function announcementFormSchema(): array
    {
        $announcements = app(MemberAnnouncementService::class);

        return [
            Select::make('audience')
                ->label(__('Recipients'))
                ->options(MemberAnnouncement::audienceOptions())
                ->default(MemberAnnouncement::AUDIENCE_ALL_ACTIVE)
                ->required()
                ->live()
                ->helperText(fn (?string $state): string => $state === null
                    ? ''
                    : __('Matches :count member(s) with portal accounts.', [
                        'count' => $announcements->previewCount($state),
                    ])),
            TextInput::make('title_en')
                ->label(__('Message title (English)'))
                ->required()
                ->maxLength(150),
            TextInput::make('title_ar')
                ->label(__('Message title (Arabic)'))
                ->maxLength(150),
            Textarea::make('body_en')
                ->label(__('Message body (English)'))
                ->rows(4)
                ->required()
                ->maxLength(5000),
            Textarea::make('body_ar')
                ->label(__('Message body (Arabic)'))
                ->rows(4)
                ->maxLength(5000),
            CheckboxList::make('channels')
                ->label(__('Delivery channels'))
                ->options(MemberAnnouncement::channelOptions())
                ->default([MemberAnnouncement::CHANNEL_IN_APP])
                ->required()
                ->columns(3),
            DateTimePicker::make('scheduled_for')
                ->label(__('Schedule for'))
                ->native(false)
                ->minDate(now())
                ->helperText(__('Leave empty to send immediately.')),
        ];
    }

    /**
     * @return array<int, Textarea|FileUpload>
     */
    protected function bulkMessageFormSchema(): array
    {
        return [
            Textarea::make('body')
                ->label(__('Message'))
                ->rows(5)
                ->required()
                ->maxLength(3000),
            FileUpload::make('attachments')
                ->label(__('Attachments (optional)'))
                ->multiple()
                ->disk('public')
                ->directory('direct-messages')
                ->openable()
                ->downloadable()
                ->maxFiles(5),
        ];
    }

    /**
     * @param  EloquentCollection<int, Member>|Collection<int, Member>  $members
     */
    protected function sendMessageToMembersCollection(EloquentCollection|Collection $members, array $data): void
    {
        $admin = auth('tenant')->user();

        if (! $admin instanceof User) {
            return;
        }

        [$body, $attachments] = $this->messaging()->normalizeBodyAndAttachments(
            (string) ($data['body'] ?? ''),
            is_array($data['attachments'] ?? null) ? $data['attachments'] : [],
        );

        if ($body === '' && $attachments === []) {
            Notification::make()
                ->title(__('Message body or at least one attachment is required'))
                ->warning()
                ->send();

            return;
        }

        $sent = 0;
        $skipped = 0;

        foreach ($members as $member) {
            if (! $member instanceof Member || blank($member->user_id)) {
                $skipped++;

                continue;
            }

            if ($this->messaging()->sendAdminToMember($member, $admin, $body, $attachments, suppressAdminToast: true)) {
                $sent++;
            } else {
                $skipped++;
            }
        }

        if ($sent === 0) {
            Notification::make()
                ->title(__('No messages sent'))
                ->body($skipped > 0 ? __('No eligible members.') : '')
                ->warning()
                ->send();

            return;
        }

        Notification::make()
            ->title(__('Messages sent'))
            ->body(__('Delivered to')." {$sent} ".__('member(s)').($skipped > 0 ? '. '.__('Skipped').": {$skipped}." : '.'))
            ->success()
            ->send();
    }

    /**
     * @return Collection<int, DirectMessage>
     */
    public function conversationMessages(Member $member): Collection
    {
        $adminId = auth('tenant')->id();

        if ($adminId === null) {
            return collect();
        }

        return $this->messaging()->conversationMessagesForAdmin($member, (int) $adminId);
    }

    public function deleteConversation(Member $member): void
    {
        $deleted = $this->messaging()->purgeConversationForMember($member);

        if ($deleted === 0) {
            Notification::make()
                ->title(__('No conversation to delete'))
                ->warning()
                ->send();

            return;
        }

        Notification::make()
            ->title(__('Conversation deleted'))
            ->success()
            ->send();
    }
}
