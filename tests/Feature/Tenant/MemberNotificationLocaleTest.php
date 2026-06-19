<?php

declare(strict_types=1);

use App\Filament\Livewire\MemberDatabaseNotifications;
use App\Filament\Support\MemberDatabaseNotification;
use App\Models\Tenant\Account;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\ContributionService;
use App\Support\StoredNotificationTranslator;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
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
