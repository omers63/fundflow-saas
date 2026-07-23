<?php

declare(strict_types=1);

use App\Notifications\Concerns\DeliversToMemberChannels;
use App\Notifications\Tenant\ContributionDueNotification;
use App\Notifications\Tenant\FundPostingAcceptedNotification;
use App\Notifications\Tenant\MemberAnnouncementNotification;
use App\Notifications\Tenant\MemberDirectMessageNotification;
use App\Support\NotificationTemplateCatalog;

test('pilot member notifications are registered in the template catalog', function (string $class, string $key) {
    expect(NotificationTemplateCatalog::keyFor($class))->toBe($key)
        ->and(NotificationTemplateCatalog::categoryFor($class))->not->toBeNull();
})->with([
    [ContributionDueNotification::class, 'contribution_due'],
    [FundPostingAcceptedNotification::class, 'fund_posting_accepted'],
    [MemberDirectMessageNotification::class, 'member_direct_message'],
    [MemberAnnouncementNotification::class, 'member_announcement'],
]);

test('categorized member notifications use the shared delivery concern', function (string $class) {
    expect(class_uses_recursive($class))->toContain(DeliversToMemberChannels::class);
})->with([
    ContributionDueNotification::class,
    FundPostingAcceptedNotification::class,
    MemberDirectMessageNotification::class,
    MemberAnnouncementNotification::class,
]);
