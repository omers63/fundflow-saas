<?php

declare(strict_types=1);

namespace App\Services\Tenant;

use App\Models\Tenant\Member;
use App\Models\Tenant\MemberAnnouncement;
use App\Models\Tenant\User;
use App\Notifications\Tenant\MemberAnnouncementNotification;
use App\Support\BusinessDay;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

final class MemberAnnouncementService
{
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

        if ($announcement->scheduled_for !== null && $announcement->scheduled_for->isAfter(BusinessDay::now())) {
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
            ->where('scheduled_for', '<=', BusinessDay::now())
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
            if ($this->deliverToMember($announcement, $member)) {
                $delivered++;
            }
        }

        $announcement->update([
            'recipient_count' => $members->count(),
            'delivered_count' => $delivered,
            'sent_at' => BusinessDay::now(),
        ]);

        return $announcement->fresh();
    }

    /**
     * @return Collection<int, Member>
     */
    public function resolveRecipients(string $audience): Collection
    {
        return app(MemberAudienceResolver::class)->resolve($audience);
    }

    public function previewCount(string $audience): int
    {
        return app(MemberAudienceResolver::class)->previewCount($audience);
    }

    private function deliverToMember(MemberAnnouncement $announcement, Member $member): bool
    {
        if ($member->user_id === null) {
            return false;
        }

        $notifiable = $member->user;

        if ($notifiable === null) {
            return false;
        }

        $locale = $notifiable->preferredLocale() ?? config('app.locale');
        $title = $locale === 'ar' && filled($announcement->title_ar)
            ? (string) $announcement->title_ar
            : $announcement->title_en;
        $body = $locale === 'ar' && filled($announcement->body_ar)
            ? (string) $announcement->body_ar
            : $announcement->body_en;

        $channels = $announcement->channels ?? [];
        $sendInApp = in_array(MemberAnnouncement::CHANNEL_IN_APP, $channels, true);
        $sendEmail = in_array(MemberAnnouncement::CHANNEL_EMAIL, $channels, true);
        $sendSms = in_array(MemberAnnouncement::CHANNEL_SMS, $channels, true);

        if (! $sendInApp && ! $sendEmail && ! $sendSms) {
            return false;
        }

        Notification::send($notifiable, new MemberAnnouncementNotification(
            $title,
            $body,
            $sendInApp,
            $sendEmail,
            $sendSms,
        ));

        return true;
    }
}
