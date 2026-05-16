<?php

namespace App\Filament\Tenant\Resources\Members\RelationManagers;

use App\Filament\Concerns\TranslatesRelationManagerTitle;
use App\Filament\Resources\RelationManagers\RelationManager;
use App\Filament\Support\TableRecordActionGroups;
use App\Models\Tenant\DirectMessage;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MessagesRelationManager extends RelationManager
{
    use TranslatesRelationManagerTitle;

    protected static string $relationship = 'directMessages';

    protected static ?string $title = 'Messages';

    public function form(Schema $schema): Schema
    {
        return $schema->schema([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function (Builder $query): Builder {
                /** @var Member $member */
                $member = $this->getOwnerRecord();
                $memberUserId = (int) $member->user_id;

                return $query
                    ->where(function (Builder $q) use ($memberUserId): void {
                        $q->where('from_user_id', $memberUserId)
                            ->orWhere('to_user_id', $memberUserId);
                    })
                    ->where(function (Builder $q) use ($memberUserId): void {
                        $q->where(function (Builder $q2) use ($memberUserId): void {
                            $q2->where('from_user_id', $memberUserId)
                                ->whereHas('recipient', fn (Builder $r) => $r->where('is_admin', true));
                        })->orWhere(function (Builder $q2) use ($memberUserId): void {
                            $q2->where('to_user_id', $memberUserId)
                                ->whereHas('sender', fn (Builder $s) => $s->where('is_admin', true));
                        });
                    })
                    ->with(['sender', 'recipient']);
            })
            ->defaultSort('created_at', 'desc')
            ->headerActions([
                Action::make('send_message')
                    ->label(__('Send message'))
                    ->icon('heroicon-o-chat-bubble-left-right')
                    ->color('info')
                    ->visible(fn (): bool => $this->getOwnerRecord()->user !== null)
                    ->modalHeading(fn (): string => __('Send message to :name', ['name' => $this->getOwnerRecord()->user->name ?? __('Member')]))
                    ->modalWidth('lg')
                    ->schema([
                        TextInput::make('subject')
                            ->required()
                            ->maxLength(150),
                        Textarea::make('body')
                            ->label(__('Message'))
                            ->required()
                            ->rows(5)
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
                    ->action(function (array $data): void {
                        /** @var Member $member */
                        $member = $this->getOwnerRecord();
                        $admin = auth('tenant')->user();
                        if ($admin === null || $member->user_id === null) {
                            return;
                        }

                        $attachments = is_array($data['attachments'] ?? null)
                            ? array_values(array_filter($data['attachments'], fn ($file): bool => filled($file)))
                            : [];

                        $root = DirectMessage::query()
                            ->root()
                            ->where(function (Builder $q) use ($member): void {
                                $q->where(function (Builder $sq) use ($member): void {
                                    $sq->where('from_user_id', $member->user_id)
                                        ->whereHas('recipient', fn (Builder $admin): Builder => $admin->where('is_admin', true));
                                })->orWhere(function (Builder $sq) use ($member): void {
                                    $sq->where('to_user_id', $member->user_id)
                                        ->whereHas('sender', fn (Builder $admin): Builder => $admin->where('is_admin', true));
                                });
                            })
                            ->orderBy('created_at')
                            ->first();

                        if ($root === null) {
                            DirectMessage::create([
                                'from_user_id' => $admin->id,
                                'to_user_id' => $member->user_id,
                                'subject' => $data['subject'],
                                'body' => $data['body'],
                                'attachments' => $attachments,
                            ]);
                        } else {
                            DirectMessage::create([
                                'from_user_id' => $admin->id,
                                'to_user_id' => $member->user_id,
                                'parent_id' => $root->id,
                                'subject' => $root->subject ?: $data['subject'],
                                'body' => $data['body'],
                                'attachments' => $attachments,
                            ]);
                        }

                        $memberUser = $member->user;
                        if ($memberUser !== null) {
                            Notification::make()
                                ->title(__('New message from administration'))
                                ->body($data['subject'].': '.mb_strimwidth($data['body'], 0, 100, '...'))
                                ->icon('heroicon-o-chat-bubble-left-right')
                                ->iconColor('info')
                                ->sendToDatabase($memberUser);
                        }

                        Notification::make()
                            ->title(__('Message sent to :name', ['name' => $member->user->name ?? __('member')]))
                            ->success()
                            ->send();
                    }),
            ])
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('Sent'))
                    ->formatStateUsing(
                        fn ($state): string => $state ? Carbon::parse($state)->locale(app()->getLocale())->translatedFormat('d M Y H:i') : __('—')
                    )
                    ->sortable(),
                TextColumn::make('direction')
                    ->label(__('Direction'))
                    ->badge()
                    ->getStateUsing(function (DirectMessage $record): string {
                        $memberUserId = (int) $this->getOwnerRecord()->user_id;

                        return (int) $record->from_user_id === $memberUserId
                            ? __('Member → Admin')
                            : __('Admin → Member');
                    })
                    ->color(fn (string $state): string => $state === __('Member → Admin') ? 'info' : 'success'),
                TextColumn::make('subject')
                    ->formatStateUsing(fn (?string $state): string => filled($state) ? $state : __('No subject'))
                    ->searchable(),
                TextColumn::make('body')
                    ->label(__('Message'))
                    ->limit(120)
                    ->searchable(),
                TextColumn::make('sender.name')
                    ->label(__('From'))
                    ->placeholder(__('—'))
                    ->searchable(),
                TextColumn::make('recipient.name')
                    ->label(__('To'))
                    ->placeholder(__('—'))
                    ->searchable(),
                TextColumn::make('read_at')
                    ->label(__('Read'))
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state ? __('Read') : __('Unread'))
                    ->color(fn ($state): string => $state ? 'success' : 'warning'),
            ])
            ->filters([
                SelectFilter::make('direction')
                    ->options([
                        'member_to_admin' => __('Member → Admin'),
                        'admin_to_member' => __('Admin → Member'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $memberUserId = (int) $this->getOwnerRecord()->user_id;

                        return match ($data['value'] ?? null) {
                            'member_to_admin' => $query->where('from_user_id', $memberUserId),
                            'admin_to_member' => $query->where('to_user_id', $memberUserId),
                            default => $query,
                        };
                    }),
                TernaryFilter::make('read_at')
                    ->label(__('Read state'))
                    ->trueLabel(__('Read'))
                    ->falseLabel(__('Unread')),
            ])
            ->recordActions(TableRecordActionGroups::wrap([
                Action::make('reply')
                    ->label(__('Reply'))
                    ->icon('heroicon-o-paper-airplane')
                    ->color('primary')
                    ->schema([
                        Textarea::make('body')
                            ->label(__('Reply'))
                            ->required()
                            ->rows(4)
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
                    ->action(function (DirectMessage $record, array $data): void {
                        $member = $this->getOwnerRecord();
                        $admin = auth('tenant')->user();
                        if ($admin === null || $member->user_id === null) {
                            return;
                        }

                        $toUserId = (int) $member->user_id;
                        $attachments = is_array($data['attachments'] ?? null)
                            ? array_values(array_filter($data['attachments'], fn ($file): bool => filled($file)))
                            : [];

                        DirectMessage::create([
                            'from_user_id' => $admin->id,
                            'to_user_id' => $toUserId,
                            'parent_id' => $record->parent_id ?: $record->id,
                            'subject' => $record->subject,
                            'body' => $data['body'],
                            'attachments' => $attachments,
                        ]);

                        $recipient = User::find($toUserId);
                        if ($recipient !== null) {
                            Notification::make()
                                ->title(__('Reply: :subject', ['subject' => $record->subject ?: __('Message')]))
                                ->body($admin->name.': '.mb_strimwidth($data['body'], 0, 100, '...'))
                                ->icon('heroicon-o-chat-bubble-left-right')
                                ->iconColor('info')
                                ->sendToDatabase($recipient);
                        }

                        Notification::make()
                            ->title(__('Reply sent'))
                            ->success()
                            ->send();
                    }),
            ]))
            ->emptyStateHeading(__('No direct messages'))
            ->emptyStateDescription(__('No messages have been exchanged with this member yet.'));
    }
}
