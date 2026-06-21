<?php

declare(strict_types=1);

namespace App\Services\Tenant;

use App\Models\Tenant\Member;
use App\Models\Tenant\MemberAnnouncement;
use App\Models\Tenant\User;
use App\Notifications\Tenant\MemberAnnouncementNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

final class MemberAnnouncementService
{
    public function __construct(
        private DirectMessagingService $messaging,
    ) {}

    /**
     * @param  array{
     *     audience: string,
     *     title_en: string,
     *     title_ar?: string|null,
     *     body_en: string,
     *     body_ar?: string|null,
     *     channels: list<string>,
     *     scheduled_for?: \DateTimeInterface|null,
     * }  $payload
     */
    public function createAndDispatch(User $admin, array $payload): MemberAnnouncement
    {
        $channels = array_values(array_intersect(
            $payload['channels'] ?? [],
            array_keys(MemberAnnouncement::channelOptions()),
        ));

        if ($channels === []) {
            throw new \InvalidArgumentException(__('Select at least one delivery channel.'));
        }

        $announcement = MemberAnnouncement::query()->create([
            'created_by_user_id' => $admin->id,
            'audience' => $payload['audience'],
            'title_en' => $payload['title_en'],
            'title_ar' => $payload['title_ar'] ?? null,
            'body_en' => $payload['body_en'],
            'body_ar' => $payload['body_ar'] ?? null,
            'channels' => $channels,
            'scheduled_for' => $payload['scheduled_for'] ?? null,
        ]);

        if ($announcement->scheduled_for !== null && $announcement->scheduled_for->isFuture()) {
            return $announcement;
        }

        return $this->dispatch($announcement, $admin);
    }

    public function dispatchDueScheduled(): int
    {
        $dispatched = 0;

        MemberAnnouncement::query()
            ->whereNull('sent_at')
            ->whereNotNull('scheduled_for')
            ->where('scheduled_for', '<=', now())
            ->orderBy('scheduled_for')
            ->each(function (MemberAnnouncement $announcement) use (&$dispatched): void {
                $admin = $announcement->createdBy;

                if ($admin === null) {
                    $admin = User::query()->where('is_admin', true)->first();
                }

                if ($admin === null) {
                    return;
                }

                $this->dispatch($announcement, $admin);
                $dispatched++;
            });

        return $dispatched;
    }

    public function dispatch(MemberAnnouncement $announcement, User $admin): MemberAnnouncement
    {
        $members = $this->resolveRecipients($announcement->audience);
        $delivered = 0;

        foreach ($members as $member) {
            if ($this->deliverToMember($announcement, $member, $admin)) {
                $delivered++;
            }
        }

        $announcement->update([
            'recipient_count' => $members->count(),
            'delivered_count' => $delivered,
            'sent_at' => now(),
        ]);

        return $announcement->fresh();
    }

    /**
     * @return Collection<int, Member>
     */
    public function resolveRecipients(string $audience): Collection
    {
        $query = Member::query()
            ->whereNotNull('user_id')
            ->with('user');

        return match ($audience) {
            MemberAnnouncement::AUDIENCE_OVERDUE => $query
                ->whereHas('contributions', fn (Builder $q): Builder => $q->where('status', 'overdue'))
                ->get(),
            MemberAnnouncement::AUDIENCE_DELINQUENT => $query
                ->where('status', 'delinquent')
                ->get(),
            MemberAnnouncement::AUDIENCE_WITH_ACTIVE_LOANS => $query
                ->whereHas('loans', fn (Builder $q): Builder => $q->whereIn('status', ['active', 'transferred', 'repaying', 'disbursed']))
                ->get(),
            default => $query->where('status', 'active')->get(),
        };
    }

    public function previewCount(string $audience): int
    {
        return $this->resolveRecipients($audience)->count();
    }

    private function deliverToMember(MemberAnnouncement $announcement, Member $member, User $admin): bool
    {
        if ($member->user_id === null) {
            return false;
        }

        $locale = $member->user?->locale ?? config('app.locale');
        $title = $locale === 'ar' && filled($announcement->title_ar)
            ? (string) $announcement->title_ar
            : $announcement->title_en;
        $body = $locale === 'ar' && filled($announcement->body_ar)
            ? (string) $announcement->body_ar
            : $announcement->body_en;

        $delivered = false;
        $channels = $announcement->channels ?? [];

        if (in_array(MemberAnnouncement::CHANNEL_IN_APP, $channels, true)) {
            $delivered = $this->messaging->sendAdminToMember(
                $member,
                $admin,
                $body,
                [],
                suppressAdminToast: true,
                subject: $title,
            ) || $delivered;
        }

        if (
            in_array(MemberAnnouncement::CHANNEL_EMAIL, $channels, true)
            || in_array(MemberAnnouncement::CHANNEL_SMS, $channels, true)
        ) {
            $notifiable = $member->user;

            if ($notifiable !== null) {
                Notification::send($notifiable, new MemberAnnouncementNotification(
                    $title,
                    $body,
                    in_array(MemberAnnouncement::CHANNEL_EMAIL, $channels, true),
                    in_array(MemberAnnouncement::CHANNEL_SMS, $channels, true),
                ));
                $delivered = true;
            }
        }

        return $delivered;
    }
}
