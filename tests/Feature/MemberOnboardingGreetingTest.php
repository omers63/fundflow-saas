<?php

declare(strict_types=1);

use App\Console\Commands\MembersSendOnboardingGreetingCommand;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Notifications\Concerns\DeliversToMemberChannels;
use App\Notifications\Tenant\MemberOnboardingGreetingNotification;
use App\Services\AccountingService;
use App\Services\Tenant\HouseholdMemberService;
use App\Services\Tenant\MemberOnboardingGreetingService;
use App\Support\NotificationTemplateCatalog;
use App\Support\ScheduledJobRegistry;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function (): void {
    $this->initializeTenancy();
    Filament::setCurrentPanel('tenant');
    NotificationTemplateCatalog::seedMissingDefaults();
});

test('onboarding greeting is registered in the template catalog and job registry', function (): void {
    expect(NotificationTemplateCatalog::keyFor(MemberOnboardingGreetingNotification::class))
        ->toBe('member_onboarding_greeting')
        ->and(NotificationTemplateCatalog::categoryFor(MemberOnboardingGreetingNotification::class))
        ->not->toBeNull()
        ->and(class_uses_recursive(MemberOnboardingGreetingNotification::class))
        ->toContain(DeliversToMemberChannels::class)
        ->and(Artisan::all())->toHaveKey('members:send-onboarding-greeting');

    $keys = array_column(ScheduledJobRegistry::all(), 'key');

    expect($keys)->toContain('members:send-onboarding-greeting')
        ->and(ScheduledJobRegistry::find('members:send-onboarding-greeting')['schedule'])->toBe(__('Manual'));
});

test('onboarding greeting email uses fund name and member name variables', function (): void {
    Notification::fake();

    $user = User::create([
        'name' => 'Welcome Member',
        'email' => 'welcome-member@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
        'preferred_locale' => 'en',
    ]);

    $member = Member::create([
        'user_id' => $user->id,
        'member_number' => 'ONB-1',
        'name' => 'Welcome Member',
        'email' => 'welcome-member@fund.test',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subMonth(),
        'status' => 'active',
    ]);
    app(AccountingService::class)->createMemberAccounts($member);

    expect(app(MemberOnboardingGreetingService::class)->sendToMember($member))->toBeTrue();

    Notification::assertSentTo($user, MemberOnboardingGreetingNotification::class, function (MemberOnboardingGreetingNotification $notification) use ($member, $user): bool {
        $mail = $notification->toMail($user);
        $rendered = (string) $mail->render();
        $subject = (string) $mail->subject;

        expect($subject)->toContain('Welcome')
            ->and($rendered)->toContain($member->name)
            ->and($rendered)->toContain('Welcome aboard')
            ->and($rendered)->toContain('Accounts · money flow')
            ->and($rendered)->toContain('Your accounts')
            ->and($rendered)->toContain('Cash')
            ->and($rendered)->toContain('Fund')
            ->and($rendered)->toContain('Loan')
            ->and($rendered)->toContain('How money moves')
            ->and($rendered)->toContain('Deposits')
            ->and($rendered)->toContain('Install the app')
            ->and($rendered)->toContain('Android')
            ->and($rendered)->toContain('iPhone')
            ->and($rendered)->toContain('border-radius:16px');

        return true;
    });
});

test('arabic onboarding greeting email is rendered right to left', function () {
    Notification::fake();
    NotificationTemplateCatalog::restoreDefaults('member_onboarding_greeting');

    $user = User::create([
        'name' => 'عضو ترحيب',
        'email' => 'welcome-ar@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
        'preferred_locale' => 'ar',
    ]);

    $member = Member::create([
        'user_id' => $user->id,
        'member_number' => 'ONB-AR-1',
        'name' => 'عضو ترحيب',
        'email' => 'welcome-ar@fund.test',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subMonth(),
        'status' => 'active',
    ]);
    app(AccountingService::class)->createMemberAccounts($member);

    expect(app(MemberOnboardingGreetingService::class)->sendToMember($member))->toBeTrue();

    Notification::assertSentTo($user, MemberOnboardingGreetingNotification::class, function (MemberOnboardingGreetingNotification $notification) use ($user): bool {
        $rendered = (string) $notification->toMail($user)->render();

        expect($rendered)->toContain('dir="rtl"')
            ->and($rendered)->toContain('direction:rtl')
            ->and($rendered)->toContain('مرحبًا بك معنا')
            ->and($rendered)->toContain('الحسابات · حركة الأموال')
            ->and($rendered)->toContain('حساباتك')
            ->and($rendered)->toContain('النقد')
            ->and($rendered)->toContain('كيف تتحرك الأموال')
            ->and($rendered)->toContain('border-radius:16px');

        return true;
    });
});

test('onboarding greeting command notifies active members', function (): void {
    Notification::fake();

    $user = User::create([
        'name' => 'Batch Welcome',
        'email' => 'batch-welcome@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
    ]);

    $member = Member::create([
        'user_id' => $user->id,
        'member_number' => 'ONB-2',
        'name' => 'Batch Welcome',
        'email' => 'batch-welcome@fund.test',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subMonth(),
        'status' => 'active',
    ]);
    app(AccountingService::class)->createMemberAccounts($member);

    $this->artisan(MembersSendOnboardingGreetingCommand::class)
        ->assertSuccessful();

    Notification::assertSentTo($user, MemberOnboardingGreetingNotification::class);
});

test('admin-created members receive the onboarding greeting by default', function (): void {
    Notification::fake();

    $member = app(HouseholdMemberService::class)->createFromAdmin([
        'name' => 'Admin Created Welcome',
        'email' => 'admin-created-welcome@fund.test',
        'monthly_contribution_amount' => 500,
        'joined_at' => now(),
        'status' => 'active',
    ], 'password');

    Notification::assertSentTo($member->user, MemberOnboardingGreetingNotification::class);
});

test('admin-created members can skip the onboarding greeting', function (): void {
    Notification::fake();

    $member = app(HouseholdMemberService::class)->createFromAdmin([
        'name' => 'Import Style Member',
        'email' => 'import-style-welcome@fund.test',
        'monthly_contribution_amount' => 500,
        'joined_at' => now(),
        'status' => 'active',
    ], 'password', sendOnboardingGreeting: false);

    Notification::assertNotSentTo($member->user, MemberOnboardingGreetingNotification::class);
});
