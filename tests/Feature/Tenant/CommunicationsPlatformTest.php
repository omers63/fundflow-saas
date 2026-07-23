<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\DirectMessage;
use App\Models\Tenant\Loan;
use App\Models\Tenant\Member;
use App\Models\Tenant\MemberAnnouncement;
use App\Models\Tenant\MemberCommunicationPreference;
use App\Models\Tenant\NotificationTemplate;
use App\Models\Tenant\User;
use App\Notifications\Tenant\ContributionDueNotification;
use App\Notifications\Tenant\FundPostingAcceptedNotification;
use App\Notifications\Tenant\GenericMemberAlertNotification;
use App\Notifications\Tenant\LoanSubmittedNotification;
use App\Notifications\Tenant\MemberAnnouncementNotification;
use App\Notifications\Tenant\MemberDirectMessageNotification;
use App\Notifications\Tenant\NewLoanApplicationNotification;
use App\Services\AccountingService;
use App\Services\Tenant\MemberAnnouncementService;
use App\Services\Tenant\MemberPortalNotificationService;
use App\Services\Tenant\NotificationPreferenceService;
use App\Services\Tenant\NotificationTemplateRenderer;
use App\Support\NotificationTemplateCatalog;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Filament::setCurrentPanel('member');

    Account::query()->delete();
    Member::query()->delete();
    User::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fees', 'name' => 'Master Fees', 'balance' => 0, 'is_master' => true]);

    NotificationTemplateCatalog::seedMissingDefaults();
});

test('notification template renderer merges variables and falls back to defaults', function () {
    $rendered = app(NotificationTemplateRenderer::class)->render(
        'contribution_due',
        NotificationTemplate::FAMILY_EMAIL,
        'en',
        [
            'amount' => '100.00',
            'period' => 'July 2026',
            'deadline' => '20 Jul 2026',
            'balance' => '50.00',
        ],
    );

    expect($rendered['subject'])->toBe('Contribution due')
        ->and($rendered['body'])->toContain('100.00')
        ->and($rendered['body'])->toContain('July 2026');
});

test('restore defaults overwrites edited template rows', function () {
    NotificationTemplate::query()
        ->where('key', 'contribution_due')
        ->where('locale', 'en')
        ->where('channel_family', NotificationTemplate::FAMILY_EMAIL)
        ->update([
            'subject' => 'Custom subject',
            'body_markdown' => 'Custom body {{amount}}',
        ]);

    NotificationTemplateCatalog::restoreDefaults('contribution_due');

    $row = NotificationTemplate::query()
        ->where('key', 'contribution_due')
        ->where('locale', 'en')
        ->where('channel_family', NotificationTemplate::FAMILY_EMAIL)
        ->first();

    expect($row)->not->toBeNull()
        ->and($row->subject)->toBe('Contribution due')
        ->and($row->body_markdown)->toContain('{{amount}}');
});

test('contribution due notification honors member channel preferences', function () {
    Notification::fake();

    $memberUser = User::create([
        'name' => 'Prefs Member',
        'email' => 'prefs-member@test.com',
        'password' => bcrypt('password'),
        'is_admin' => false,
    ]);

    MemberCommunicationPreference::saveFor(
        $memberUser->id,
        NotificationPreferenceService::CONTRIBUTIONS,
        [NotificationPreferenceService::CH_IN_APP],
        [NotificationPreferenceService::CH_IN_APP],
    );

    $notification = new ContributionDueNotification(
        month: 7,
        year: 2026,
        amount: 100,
        deadline: Carbon::parse('2026-07-20'),
        cashBalance: 50,
        memberName: 'Prefs Member',
    );

    $channels = $notification->via($memberUser);

    expect($channels)->toContain('database')
        ->and($channels)->not->toContain('mail');
});

test('fund posting accepted notification uses account alerts preferences', function () {
    Notification::fake();

    $memberUser = User::create([
        'name' => 'Deposit Member',
        'email' => 'deposit-member@test.com',
        'password' => bcrypt('password'),
        'is_admin' => false,
    ]);

    $member = Member::create([
        'user_id' => $memberUser->id,
        'member_number' => 'MEM-DEP-01',
        'name' => 'Deposit Member',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    app(AccountingService::class)->createMemberAccounts($member);

    MemberCommunicationPreference::saveFor(
        $memberUser->id,
        NotificationPreferenceService::ACCOUNT_ALERTS,
        [NotificationPreferenceService::CH_IN_APP],
        [NotificationPreferenceService::CH_IN_APP, NotificationPreferenceService::CH_EMAIL],
    );

    $posting = $member->fundPostings()->create([
        'member_id' => $member->id,
        'posting_date' => now()->toDateString(),
        'amount' => 250,
        'status' => 'accepted',
    ]);

    $notification = new FundPostingAcceptedNotification($posting);
    $channels = $notification->via($memberUser);

    // Account alerts force in-app (+ email when the system allows); prefs still apply to optional channels.
    expect($channels)->toContain('database')
        ->and(NotificationTemplateCatalog::keyFor(FundPostingAcceptedNotification::class))->toBe('fund_posting_accepted')
        ->and(NotificationTemplateCatalog::categoryFor(FundPostingAcceptedNotification::class))
        ->toBe(NotificationPreferenceService::ACCOUNT_ALERTS);
});

test('in-app announcements create bell alerts and not direct messages', function () {
    Notification::fake();

    $admin = User::create([
        'name' => 'Announce Admin',
        'email' => 'announce-admin@test.com',
        'password' => bcrypt('password'),
        'is_admin' => true,
    ]);

    $memberUser = User::create([
        'name' => 'Announce Member',
        'email' => 'announce-member@test.com',
        'password' => bcrypt('password'),
        'is_admin' => false,
    ]);

    $member = Member::create([
        'user_id' => $memberUser->id,
        'member_number' => 'MEM-ANN-01',
        'name' => 'Announce Member',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    $beforeMessages = DirectMessage::query()->count();

    $announcement = MemberAnnouncement::query()->create([
        'created_by_user_id' => $admin->id,
        'audience' => MemberAnnouncement::AUDIENCE_ALL_ACTIVE,
        'title_en' => 'Pool notice',
        'body_en' => 'Meeting Thursday.',
        'channels' => [MemberAnnouncement::CHANNEL_IN_APP],
    ]);

    app(MemberAnnouncementService::class)->dispatch($announcement, $admin);

    expect(DirectMessage::query()->count())->toBe($beforeMessages);

    Notification::assertSentTo(
        $memberUser,
        MemberAnnouncementNotification::class,
        fn (MemberAnnouncementNotification $notification, array $channels): bool => in_array('database', $channels, true)
        && $notification->title === 'Pool notice'
        && $notification->body === 'Meeting Thursday.',
    );
});

test('member direct message notification uses broadcast template key', function () {
    expect(NotificationTemplateCatalog::keyFor(MemberDirectMessageNotification::class))->toBe('member_direct_message');

    $notification = new MemberDirectMessageNotification('Admin', 'Hello there', 'Subject line');
    $payload = $notification->toArray((object) []);

    expect($payload['title'] ?? null)->not->toBeEmpty()
        ->and($payload['body'] ?? null)->toContain('Hello there');
});

test('portal status alerts go through generic member alert notification', function () {
    Notification::fake();

    $memberUser = User::create([
        'name' => 'Portal Member',
        'email' => 'portal-member@test.com',
        'password' => bcrypt('password'),
        'is_admin' => false,
    ]);

    $member = Member::create([
        'user_id' => $memberUser->id,
        'member_number' => 'MEM-PORT-01',
        'name' => 'Portal Member',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    expect(app(MemberPortalNotificationService::class)->send($member, 'Status update', 'Your account was reviewed.'))->toBeTrue();

    Notification::assertSentTo($memberUser, GenericMemberAlertNotification::class);
});

test('branded mail message uses markdown mail view', function () {
    $mail = app(NotificationTemplateRenderer::class)->brandedMailMessage(
        'member_announcement',
        'en',
        [
            'title' => 'Hello',
            'body' => 'World **bold**',
            'action_url' => 'https://example.test/alerts',
        ],
    );

    expect($mail->subject)->toBe('Hello');
});

test('in-app and push channel families can be saved independently from email', function () {
    NotificationTemplateCatalog::seedMissingDefaults();

    NotificationTemplate::query()->updateOrCreate(
        [
            'key' => 'contribution_due',
            'locale' => 'en',
            'channel_family' => NotificationTemplate::FAMILY_IN_APP,
        ],
        [
            'subject' => 'Bell title',
            'body_markdown' => 'Bell body {{amount}}',
        ],
    );

    NotificationTemplate::query()->updateOrCreate(
        [
            'key' => 'contribution_due',
            'locale' => 'en',
            'channel_family' => NotificationTemplate::FAMILY_SMS_PUSH,
        ],
        [
            'subject' => 'Push title',
            'body_markdown' => 'Push body {{amount}}',
        ],
    );

    $renderer = app(NotificationTemplateRenderer::class);
    $vars = ['amount' => '99.00', 'period' => 'July 2026', 'deadline' => '20 Jul 2026', 'balance' => '1.00'];

    $inApp = $renderer->render('contribution_due', NotificationTemplate::FAMILY_IN_APP, 'en', $vars);
    $push = $renderer->render('contribution_due', NotificationTemplate::FAMILY_SMS_PUSH, 'en', $vars);
    $email = $renderer->render('contribution_due', NotificationTemplate::FAMILY_EMAIL, 'en', $vars);

    expect($inApp['subject'])->toBe('Bell title')
        ->and($inApp['body'])->toContain('Bell body 99.00')
        ->and($push['subject'])->toBe('Push title')
        ->and($push['body'])->toContain('Push body 99.00')
        ->and($email['subject'])->toBe('Contribution due');
});

test('in-app template edits drive previously hardcoded member bell notifications', function () {
    NotificationTemplateCatalog::seedMissingDefaults();
    app()->setLocale('en');

    NotificationTemplate::query()->updateOrCreate(
        [
            'key' => 'generic_member_alert',
            'locale' => 'en',
            'channel_family' => NotificationTemplate::FAMILY_IN_APP,
        ],
        [
            'subject' => 'Custom: {{title}}',
            'body_markdown' => 'Bell says: {{body}}',
        ],
    );

    $loan = new Loan(['amount_requested' => 15000]);
    $loan->id = 99;

    $user = new User([
        'name' => 'Bell Member',
        'email' => 'bell-member@fund.test',
        'preferred_locale' => 'en',
    ]);

    $payload = (new LoanSubmittedNotification($loan))->toDatabase($user);

    expect($payload['title'] ?? null)->toBe('Custom: Loan application submitted')
        ->and($payload['body'] ?? null)->toContain('Bell says:')
        ->and($payload['body'] ?? null)->toContain('15,000.00');
});

test('admin automation templates are listed and drive bell copy', function () {
    NotificationTemplateCatalog::seedMissingDefaults();
    app()->setLocale('en');

    $groups = NotificationTemplateCatalog::optionsGroupedByAudience();

    expect($groups['admin'])->toHaveKey('reconciliation_digest')
        ->and($groups['admin'])->toHaveKey('delinquency_digest')
        ->and($groups['admin'])->toHaveKey('new_loan_application');

    NotificationTemplate::query()->updateOrCreate(
        [
            'key' => 'new_loan_application',
            'locale' => 'en',
            'channel_family' => NotificationTemplate::FAMILY_IN_APP,
        ],
        [
            'subject' => 'Admin loan alert',
            'body_markdown' => '{{member_name}} wants {{amount}}',
        ],
    );

    $loan = new Loan(['amount_requested' => 2500]);
    $loan->id = 7;
    $loan->setRelation('member', new Member(['name' => 'Sara']));

    $admin = new User([
        'name' => 'Admin',
        'email' => 'admin-bell@fund.test',
        'preferred_locale' => 'en',
    ]);

    $payload = (new NewLoanApplicationNotification($loan))->toDatabase($admin);

    expect($payload['title'] ?? null)->toBe('Admin loan alert')
        ->and($payload['body'] ?? null)->toBe('Sara wants 2,500.00');
});
