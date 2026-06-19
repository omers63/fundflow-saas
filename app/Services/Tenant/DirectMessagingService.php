<?php

declare(strict_types=1);

namespace App\Services\Tenant;

use App\Filament\Support\MemberDatabaseNotification;
use App\Models\Tenant\DirectMessage;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class DirectMessagingService
{
    /**
     * @param  list<string>  $attachments
     * @return array{0: string, 1: list<string>}
     */
    public function normalizeBodyAndAttachments(string $body, array $attachments): array
    {
        $body = trim($body);
        $attachments = array_values(array_filter($attachments, fn (mixed $file): bool => filled($file)));

        if ($body === '' && $attachments === []) {
            return ['', []];
        }

        if ($body === '') {
            $body = ' ';
        }

        return [$body, $attachments];
    }

    public function resolveAdminRecipientForMember(int $memberUserId): ?User
    {
        $lastAdminSenderId = DirectMessage::query()
            ->where('to_user_id', $memberUserId)
            ->whereHas('sender', fn (Builder $q): Builder => $q->where('is_admin', true))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->value('from_user_id');

        if ($lastAdminSenderId !== null) {
            $admin = User::query()->find($lastAdminSenderId);

            if ($admin?->is_admin) {
                return $admin;
            }
        }

        return User::query()->where('is_admin', true)->orderBy('id')->first();
    }

    public function resolveAdminRecipientForThread(DirectMessage $root, int $memberUserId): ?User
    {
        $threadIds = collect([$root->id])->merge($root->replies()->pluck('id'));

        $lastAdminSenderId = DirectMessage::query()
            ->whereIn('id', $threadIds)
            ->where('to_user_id', $memberUserId)
            ->whereHas('sender', fn (Builder $q): Builder => $q->where('is_admin', true))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->value('from_user_id');

        if ($lastAdminSenderId !== null) {
            $admin = User::query()->find($lastAdminSenderId);

            if ($admin?->is_admin) {
                return $admin;
            }
        }

        return $this->resolveAdminRecipientForMember($memberUserId);
    }

    public function findRootForMember(Member $member): ?DirectMessage
    {
        if ($member->user_id === null) {
            return null;
        }

        return DirectMessage::query()
            ->root()
            ->where(function (Builder $q) use ($member): void {
                $this->applyMemberAdminConversationScope($q, $member);
            })
            ->orderBy('created_at')
            ->first();
    }

    /**
     * @return Collection<int, DirectMessage>
     */
    public function conversationMessagesForAdmin(Member $member, int $adminUserId): Collection
    {
        if ($member->user_id === null) {
            return collect();
        }

        DirectMessage::query()
            ->where('from_user_id', $member->user_id)
            ->where('to_user_id', $adminUserId)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return DirectMessage::query()
            ->where(function (Builder $q) use ($member): void {
                $this->applyMemberAdminConversationScope($q, $member);
            })
            ->with(['sender', 'recipient'])
            ->orderBy('created_at')
            ->get();
    }

    /**
     * @param  list<string>  $attachments
     */
    public function sendAdminToMember(
        Member $member,
        User $admin,
        string $body,
        array $attachments = [],
        bool $suppressAdminToast = false,
        ?string $subject = null,
    ): bool {
        [$body, $attachments] = $this->normalizeBodyAndAttachments($body, $attachments);

        if ($body === '' && $attachments === []) {
            if (! $suppressAdminToast) {
                Notification::make()
                    ->title(__('Message body or at least one attachment is required'))
                    ->warning()
                    ->send();
            }

            return false;
        }

        if ($member->user_id === null) {
            if (! $suppressAdminToast) {
                Notification::make()
                    ->title(__('Member account not found'))
                    ->danger()
                    ->send();
            }

            return false;
        }

        $root = $this->findRootForMember($member);

        if ($root === null) {
            DirectMessage::create([
                'from_user_id' => $admin->id,
                'to_user_id' => $member->user_id,
                'subject' => $subject ?? __('Conversation with :name', ['name' => $member->user?->name ?? __('member')]),
                'body' => $body,
                'attachments' => $attachments,
            ]);
        } else {
            DirectMessage::create([
                'from_user_id' => $admin->id,
                'to_user_id' => $member->user_id,
                'parent_id' => $root->id,
                'subject' => $root->subject,
                'body' => $body,
                'attachments' => $attachments,
            ]);
        }

        $recipient = $member->user;

        if ($recipient !== null) {
            MemberDatabaseNotification::send($recipient, function (Notification $notification) use ($admin, $body): void {
                $notification
                    ->title(__('Message from Administration'))
                    ->body($admin->name.': '.mb_strimwidth(trim($body), 0, 100, '…'))
                    ->icon('heroicon-o-chat-bubble-left-right')
                    ->iconColor('info');
            });
        }

        if (! $suppressAdminToast) {
            Notification::make()
                ->title(__('Message sent'))
                ->success()
                ->send();
        }

        return true;
    }

    /**
     * @param  list<string>  $attachments
     */
    public function sendMemberToAdmin(
        User $memberUser,
        User $admin,
        string $body,
        array $attachments = [],
        ?string $subject = null,
        ?DirectMessage $replyToRoot = null,
    ): DirectMessage {
        [$body, $attachments] = $this->normalizeBodyAndAttachments($body, $attachments);

        if ($replyToRoot !== null) {
            return DirectMessage::create([
                'from_user_id' => $memberUser->id,
                'to_user_id' => $admin->id,
                'parent_id' => $replyToRoot->id,
                'subject' => $replyToRoot->subject,
                'body' => $body,
                'attachments' => $attachments,
            ]);
        }

        return DirectMessage::create([
            'from_user_id' => $memberUser->id,
            'to_user_id' => $admin->id,
            'subject' => $subject ?? __('Message to administration'),
            'body' => $body,
            'attachments' => $attachments,
        ]);
    }

    public function notifyAdminsOfMemberMessage(User $memberUser, string $subject, string $body): void
    {
        $title = __('New message from :name', ['name' => $memberUser->name]);
        $preview = mb_strimwidth($body, 0, 100, '…');

        User::query()
            ->where('is_admin', true)
            ->each(function (User $admin) use ($title, $subject, $preview): void {
                Notification::make()
                    ->title($title)
                    ->body($subject.': '.$preview)
                    ->icon('heroicon-o-chat-bubble-left-right')
                    ->iconColor('info')
                    ->sendToDatabase($admin);
            });
    }

    public function notifyAdminsOfMemberReply(User $memberUser, string $subject, string $body): void
    {
        User::query()
            ->where('is_admin', true)
            ->each(function (User $admin) use ($memberUser, $subject, $body): void {
                Notification::make()
                    ->title(__('Reply from :name', ['name' => $memberUser->name]))
                    ->body($subject.': '.mb_strimwidth($body, 0, 100, '…'))
                    ->icon('heroicon-o-chat-bubble-left-right')
                    ->iconColor('info')
                    ->sendToDatabase($admin);
            });
    }

    public function markMemberThreadRead(DirectMessage $record, int $memberUserId): void
    {
        $rootId = $record->parent_id ?? $record->id;

        DirectMessage::query()
            ->where(function (Builder $query) use ($rootId): void {
                $query->where('id', $rootId)->orWhere('parent_id', $rootId);
            })
            ->where('to_user_id', $memberUserId)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    public function purgeConversationForMember(Member $member): int
    {
        if ($member->user_id === null) {
            return 0;
        }

        return DirectMessage::query()
            ->where(function (Builder $q) use ($member): void {
                $this->applyMemberAdminConversationScope($q, $member);
            })
            ->delete();
    }

    public function unreadCountForAdmin(int $adminUserId): int
    {
        return DirectMessage::query()
            ->where('to_user_id', $adminUserId)
            ->whereNull('read_at')
            ->count();
    }

    public function applyMemberAdminConversationScope(Builder $query, Member $member): Builder
    {
        $memberUserId = (int) $member->user_id;

        return $query->where(function (Builder $q) use ($memberUserId): void {
            $q->where(function (Builder $sq) use ($memberUserId): void {
                $sq->where('to_user_id', $memberUserId)
                    ->whereHas('sender', fn (Builder $admin): Builder => $admin->where('is_admin', true));
            })->orWhere(function (Builder $sq) use ($memberUserId): void {
                $sq->where('from_user_id', $memberUserId)
                    ->whereHas('recipient', fn (Builder $admin): Builder => $admin->where('is_admin', true));
            });
        });
    }
}
