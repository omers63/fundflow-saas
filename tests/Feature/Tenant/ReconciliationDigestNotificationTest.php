<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\Member;
use App\Models\Tenant\ReconciliationSnapshot;
use App\Models\Tenant\User;
use App\Notifications\Tenant\ReconciliationDigestNotification;
use App\Services\ReconciliationDigestService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Filament::setCurrentPanel('tenant');

    config([
        'webpush.vapid.public_key' => 'BEl62iUYgUivxIkv69yViEuiBIa-Ib9-SkvMeAtA3LFgDzkrxZJjSgSnfckjBJuBkr3qBUYIHBQFLXYp5Nksh8U',
        'webpush.vapid.private_key' => 'UUxI4O8-FbRqjAihg6f42nd_pmTQj2vmanuelys70Ho',
    ]);

    Account::query()->delete();
    Member::query()->delete();
    User::query()->delete();

    $this->admin = User::create([
        'name' => 'Recon Admin',
        'email' => 'recon-admin@test.com',
        'password' => bcrypt('password'),
        'is_admin' => true,
    ]);

    $this->nonAdmin = User::create([
        'name' => 'Recon Member',
        'email' => 'recon-member@test.com',
        'password' => bcrypt('password'),
        'is_admin' => false,
    ]);
});

test('nightly batch digest notifies admins via database and web push', function () {
    Notification::fake();

    $notified = app(ReconciliationDigestService::class)->notifyAdminsOfNightlyBatch([
        'halted' => false,
        'raised' => 3,
        'resolved' => 1,
        'critical' => 0,
    ]);

    expect($notified)->toBe(1);

    Notification::assertSentTo(
        $this->admin,
        ReconciliationDigestNotification::class,
        fn (ReconciliationDigestNotification $notification, array $channels): bool => $notification->mode === 'nightly'
            && $notification->critical === false
            && in_array('database', $channels, true)
            && in_array(WebPushChannel::class, $channels, true),
    );

    Notification::assertNotSentTo($this->nonAdmin, ReconciliationDigestNotification::class);
});

test('halted nightly batch digest is flagged critical', function () {
    Notification::fake();

    app(ReconciliationDigestService::class)->notifyAdminsOfNightlyBatch([
        'halted' => true,
        'raised' => 1,
        'resolved' => 0,
        'critical' => 1,
    ]);

    Notification::assertSentTo(
        $this->admin,
        ReconciliationDigestNotification::class,
        function (ReconciliationDigestNotification $notification): bool {
            $database = $notification->toDatabase($this->admin);

            return $notification->mode === 'nightly'
                && $notification->critical === true
                && ($database['format'] ?? null) === 'filament';
        },
    );
});

test('daily report digest reflects verdict pass', function () {
    Notification::fake();

    app(ReconciliationDigestService::class)->notifyAdminsOfReport(ReconciliationSnapshot::MODE_DAILY, [
        'verdict' => ['pass' => true, 'critical_issues' => 0, 'warnings' => 2],
        'checks' => ['ledger_balances' => ['mismatch_count' => 0]],
        'control_layer' => ['open_exception_count' => 0],
    ]);

    Notification::assertSentTo(
        $this->admin,
        ReconciliationDigestNotification::class,
        fn (ReconciliationDigestNotification $notification): bool => $notification->mode === 'daily'
            && $notification->critical === false,
    );
});

test('monthly report digest is critical when verdict fails', function () {
    Notification::fake();

    app(ReconciliationDigestService::class)->notifyAdminsOfReport(ReconciliationSnapshot::MODE_MONTHLY, [
        'verdict' => ['pass' => false, 'critical_issues' => 2, 'warnings' => 1],
        'checks' => ['ledger_balances' => ['mismatch_count' => 3]],
        'control_layer' => ['open_exception_count' => 5],
    ]);

    Notification::assertSentTo(
        $this->admin,
        ReconciliationDigestNotification::class,
        fn (ReconciliationDigestNotification $notification): bool => $notification->mode === 'monthly'
            && $notification->critical === true,
    );
});

test('nightly reconciliation command notifies admins', function () {
    Notification::fake();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fees', 'name' => 'Master Fees', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'bank', 'name' => 'Master Bank', 'balance' => 0, 'is_master' => true]);

    $this->artisan('fund:nightly-reconciliation')->assertSuccessful();

    Notification::assertSentTo($this->admin, ReconciliationDigestNotification::class);
});
