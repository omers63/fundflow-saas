<?php

declare(strict_types=1);

use App\Filament\Livewire\MemberDatabaseNotifications;
use App\Filament\Support\MemberDatabaseNotification;
use App\Filament\Support\RecipientDatabaseNotification;
use App\Models\Tenant\Account;
use App\Models\Tenant\CashOutRequest;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\FundPosting;
use App\Models\Tenant\Member;
use App\Models\Tenant\MemberAnnouncement;
use App\Models\Tenant\User;
use App\Notifications\Tenant\ContributionDueNotification;
use App\Notifications\Tenant\MemberAnnouncementNotification;
use App\Notifications\Tenant\NewCashOutRequestNotification;
use App\Notifications\Tenant\NewFundPostingNotification;
use App\Services\AccountingService;
use App\Services\ContributionCycleService;
use App\Services\ContributionService;
use App\Services\Tenant\MemberAnnouncementService;
use App\Support\MemberNotificationLocale;
use App\Support\StoredNotificationTranslator;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

test('toast notification titles translate in arabic locale', function () {
    app()->setLocale('ar');

    expect(__('Payment applied'))->toBe('تم تطبيق الدفعة')
        ->and(__('Nothing to pay'))->toBe('لا يوجد ما يُدفع')
        ->and(__('Profile updated successfully.'))->toBe('تم تحديث الملف الشخصي بنجاح.')
        ->and(__('Your settlement has been recorded. Thank you.'))->not->toContain('Your settlement')
        ->and(__('Contributions are paused'))->toBe('المساهمات متوقفة');
});

test('loan repayment skip notification body translates in arabic locale', function () {
    app()->setLocale('ar');

    $message = __('No unpaid installment in the open period (:period).', [
        'period' => 'June 2026',
    ]);

    expect($message)->toContain('June 2026')
        ->not->toContain('No unpaid installment');
});

test('contribution posted database notification is stored in member preferred locale', function () {
    $this->initializeTenancy();
    app()->setLocale('en');
    Filament::setCurrentPanel('member');

    Account::query()->delete();
    Contribution::query()->delete();
    Member::query()->delete();
    User::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fees', 'name' => 'Master Fees', 'balance' => 0, 'is_master' => true]);

    $memberUser = User::create([
        'name' => 'Arabic Member',
        'email' => 'arabic-member@test.com',
        'password' => bcrypt('password'),
        'is_admin' => false,
        'preferred_locale' => 'ar',
    ]);

    $member = Member::create([
        'user_id' => $memberUser->id,
        'member_number' => 'MEM-AR-'.uniqid(),
        'name' => 'Arabic Member',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    $accounting = app(AccountingService::class);
    $accounting->createMemberAccounts($member);

    Account::masterCash()->update(['balance' => 5000]);
    $member->cashAccount->update(['balance' => 5000]);

    $contribution = app(ContributionService::class)->recordContribution($member, '2026-05-01');
    $contribution->update(['payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT]);
    app(ContributionService::class)->postContribution($contribution);

    $stored = $memberUser->fresh()->notifications()->firstOrFail();
    $title = (string) ($stored->data['title'] ?? '');

    expect($title)->toBe('تم ترحيل المساهمة')
        ->not->toBe('Contribution posted');
});

test('filament database notification helper stores arabic title for arabic members', function () {
    $this->initializeTenancy();
    app()->setLocale('en');

    $memberUser = User::create([
        'name' => 'Arabic Member',
        'email' => 'filament-ar-member@test.com',
        'password' => bcrypt('password'),
        'is_admin' => false,
        'preferred_locale' => 'ar',
    ]);

    MemberDatabaseNotification::send($memberUser, function (Notification $notification): void {
        $notification
            ->title(__('Message from Administration'))
            ->body('Admin: test')
            ->icon('heroicon-o-bell')
            ->iconColor('info');
    });

    $stored = $memberUser->fresh()->notifications()->firstOrFail();

    expect($stored->data['title'] ?? null)->toBe('رسالة من الإدارة');
});

test('stored notification translator localizes english keys at display time', function () {
    app()->setLocale('ar');

    $localized = StoredNotificationTranslator::localize('Contribution posted');

    expect($localized)->toBe('تم ترحيل المساهمة');

    $notification = StoredNotificationTranslator::localizeFilamentNotification(
        Notification::make()->title('Contribution posted')->body('Contribution posted'),
    );

    expect($notification->getTitle())->toBe('تم ترحيل المساهمة');
});

test('member panel uses localized database notifications component', function () {
    $this->initializeTenancy();

    expect(filament()->getPanel('member')->getDatabaseNotificationsLivewireComponent())
        ->toBe(MemberDatabaseNotifications::class);
});

test('member database notifications resolve user through filament tenant guard', function () {
    $this->initializeTenancy();
    Filament::setCurrentPanel('member');

    $memberUser = User::create([
        'name' => 'Notify Member',
        'email' => 'notify-member@test.com',
        'password' => bcrypt('password'),
        'is_admin' => false,
    ]);

    Member::create([
        'user_id' => $memberUser->id,
        'member_number' => 'MEM-NOTIFY-'.uniqid(),
        'name' => 'Notify Member',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    $this->actingAs($memberUser, 'tenant');

    $component = new MemberDatabaseNotifications;

    expect($component->getUser()?->is($memberUser))->toBeTrue();
});

test('contribution due notification is stored in member preferred locale', function () {
    $this->initializeTenancy();
    app()->setLocale('en');

    Account::query()->delete();
    Member::query()->delete();
    User::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fees', 'name' => 'Master Fees', 'balance' => 0, 'is_master' => true]);

    $memberUser = User::create([
        'name' => 'Arabic Due Member',
        'email' => 'arabic-due-member@test.com',
        'password' => bcrypt('password'),
        'is_admin' => false,
        'preferred_locale' => 'ar',
    ]);

    $member = Member::create([
        'user_id' => $memberUser->id,
        'member_number' => 'MEM-DUE-AR-'.uniqid(),
        'name' => 'Arabic Due Member',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($member);

    [$month, $year] = app(ContributionCycleService::class)->currentOpenPeriod();

    expect(app(ContributionCycleService::class)->sendDueNotifications($month, $year)['notified'])->toBe(1);

    $stored = $memberUser->fresh()->notifications()->firstOrFail();

    expect($stored->data['title'] ?? null)->toBe('مساهمة مستحقة');
});

test('contribution due push title uses member preferred locale', function () {
    $this->initializeTenancy();
    app()->setLocale('en');

    $memberUser = User::create([
        'name' => 'Arabic Push Member',
        'email' => 'arabic-push-member@test.com',
        'password' => bcrypt('password'),
        'is_admin' => false,
        'preferred_locale' => 'ar',
    ]);

    $notification = new ContributionDueNotification(
        month: 7,
        year: 2026,
        amount: 1000,
        deadline: Carbon::create(2026, 7, 13),
        cashBalance: 250,
        memberName: 'Arabic Push Member',
    );

    MemberNotificationLocale::enter($memberUser);

    try {
        $push = $notification->toWebPush($memberUser, $notification)->toArray();

        expect($push['title'] ?? null)->toBe('Arabic Push Member — مساهمة مستحقة');
    } finally {
        MemberNotificationLocale::leave();
    }
});

test('admin fund posting push uses admin preferred locale', function () {
    $this->initializeTenancy();
    app()->setLocale('en');

    $admin = User::create([
        'name' => 'Arabic Admin',
        'email' => 'arabic-admin@test.com',
        'password' => bcrypt('password'),
        'is_admin' => true,
        'preferred_locale' => 'ar',
    ]);

    $member = Member::create([
        'member_number' => 'MEM-ADMIN-AR-'.uniqid(),
        'name' => 'Deposit Member',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    $posting = FundPosting::query()->create([
        'member_id' => $member->id,
        'amount' => 500,
        'status' => 'pending',
        'posting_date' => now()->toDateString(),
    ]);

    $notification = new NewFundPostingNotification($posting);

    MemberNotificationLocale::enter($admin);

    try {
        $push = $notification->toWebPush($admin, $notification)->toArray();

        expect($push['title'] ?? null)->toBe('طلب إيداع جديد');
    } finally {
        MemberNotificationLocale::leave();
    }
});

test('admin cash-out database notification uses admin preferred locale', function () {
    $this->initializeTenancy();
    app()->setLocale('en');

    $admin = User::create([
        'name' => 'Arabic Cash Admin',
        'email' => 'arabic-cash-admin@test.com',
        'password' => bcrypt('password'),
        'is_admin' => true,
        'preferred_locale' => 'ar',
    ]);

    $member = Member::create([
        'member_number' => 'MEM-CASH-AR-'.uniqid(),
        'name' => 'Cash Member',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    $cashOutRequest = CashOutRequest::query()->create([
        'member_id' => $member->id,
        'amount' => 100,
        'status' => 'pending',
    ]);

    $admin->notify(new NewCashOutRequestNotification($cashOutRequest));

    $stored = $admin->fresh()->notifications()->firstOrFail();

    expect($stored->data['title'] ?? null)->toBe('طلب سحب جديد');
});

test('recipient database notification helper stores arabic title for arabic admins', function () {
    $this->initializeTenancy();
    app()->setLocale('en');

    $admin = User::create([
        'name' => 'Arabic Admin Notify',
        'email' => 'arabic-admin-notify@test.com',
        'password' => bcrypt('password'),
        'is_admin' => true,
        'preferred_locale' => 'ar',
    ]);

    RecipientDatabaseNotification::send($admin, function (Notification $notification): void {
        $notification
            ->title(__('New member request'))
            ->body('Member — allocation')
            ->icon('heroicon-o-clipboard-document-list')
            ->iconColor('warning');
    });

    $stored = $admin->fresh()->notifications()->firstOrFail();

    expect($stored->data['title'] ?? null)->toBe('طلب عضو جديد');
});

test('member announcement email uses arabic copy for arabic members', function () {
    $this->initializeTenancy();
    app()->setLocale('en');
    NotificationFacade::fake();

    $admin = User::create([
        'name' => 'Announcement Admin',
        'email' => 'announcement-admin@test.com',
        'password' => bcrypt('password'),
        'is_admin' => true,
    ]);

    $memberUser = User::create([
        'name' => 'Arabic Announcement Member',
        'email' => 'arabic-announcement-member@test.com',
        'password' => bcrypt('password'),
        'is_admin' => false,
        'preferred_locale' => 'ar',
    ]);

    Member::create([
        'user_id' => $memberUser->id,
        'member_number' => 'MEM-ANN-AR-'.uniqid(),
        'name' => 'Arabic Announcement Member',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    $announcement = MemberAnnouncement::query()->create([
        'created_by_user_id' => $admin->id,
        'audience' => MemberAnnouncement::AUDIENCE_ALL_ACTIVE,
        'title_en' => 'Pool meeting',
        'title_ar' => 'اجتماع الصندوق',
        'body_en' => 'Annual meeting next week.',
        'body_ar' => 'الاجتماع السنوي الأسبوع القادم.',
        'channels' => [MemberAnnouncement::CHANNEL_EMAIL],
    ]);

    app(MemberAnnouncementService::class)->dispatch($announcement, $admin);

    NotificationFacade::assertSentTo(
        $memberUser,
        MemberAnnouncementNotification::class,
        fn (MemberAnnouncementNotification $notification): bool => $notification->title === 'اجتماع الصندوق'
            && $notification->body === 'الاجتماع السنوي الأسبوع القادم.',
    );
});
