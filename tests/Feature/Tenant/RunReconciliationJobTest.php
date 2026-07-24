<?php

declare(strict_types=1);

use App\Jobs\Tenant\RunReconciliationJob;
use App\Models\Tenant\Account;
use App\Models\Tenant\ReconciliationException;
use App\Models\Tenant\ReconciliationSnapshot;
use App\Models\Tenant\User;
use App\Notifications\Tenant\ReconciliationRunCompletedNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    Account::query()->delete();
    ReconciliationSnapshot::query()->delete();
    ReconciliationException::query()->delete();

    $this->admin = User::create([
        'name' => 'Recon Job Admin',
        'email' => 'recon-job-' . uniqid('', true) . '@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);
});

test('run reconciliation job stores a realtime snapshot and notifies the requester', function () {
    Notification::fake();

    $token = 'recon-ui-token-realtime';

    RunReconciliationJob::dispatchSync(
        ReconciliationSnapshot::MODE_REALTIME,
        [],
        $this->admin->id,
        $token,
    );

    $cached = Cache::get(RunReconciliationJob::uiRunCacheKey($token));

    expect(ReconciliationSnapshot::query()->count())->toBe(1)
        ->and(ReconciliationSnapshot::query()->first()?->mode)->toBe(ReconciliationSnapshot::MODE_REALTIME)
        ->and(RunReconciliationJob::uiRunStatus($cached))->toBe(RunReconciliationJob::UI_RUN_STATUS_COMPLETED)
        ->and(RunReconciliationJob::uiRunToast($cached))->not->toBeNull();

    Notification::assertSentTo($this->admin, ReconciliationRunCompletedNotification::class);
});

test('run reconciliation job localizes toast and push copy to the admin preferred locale', function () {
    Notification::fake();

    app()->setLocale('ar');

    $this->admin->update(['preferred_locale' => null]);

    $token = 'recon-ui-token-locale';

    RunReconciliationJob::dispatchSync(
        ReconciliationSnapshot::MODE_REALTIME,
        [],
        $this->admin->id,
        $token,
    );

    $cached = Cache::get(RunReconciliationJob::uiRunCacheKey($token));
    $toast = RunReconciliationJob::uiRunToast($cached);

    expect($toast)->not->toBeNull()
        ->and($toast['title'])->toBeIn([
                'Reconciliation passed',
                'Reconciliation found critical issues',
            ])
        ->and($toast['body'])->toStartWith('Snapshot #')
        ->and($toast['title'])->not->toContain('المطابقة');

    Notification::assertSentTo(
        $this->admin,
        ReconciliationRunCompletedNotification::class,
        function (ReconciliationRunCompletedNotification $notification) use ($toast): bool {
            return $notification->title === $toast['title']
                && str_starts_with($notification->summary, 'Snapshot #');
        },
    );

    expect(app()->getLocale())->toBe('ar');
});

test('run reconciliation job refreshes exception queue without storing a snapshot', function () {
    Notification::fake();

    ReconciliationException::create([
        'exception_code' => 'LEGACY_PLACEHOLDER',
        'domain' => 'ledger',
        'severity' => 'low',
        'status' => ReconciliationException::STATUS_OPEN,
        'raised_at' => now()->subDay(),
        'affected_entities' => [],
    ]);

    expect(ReconciliationException::query()->count())->toBe(1);

    $token = 'recon-ui-token-queue';

    RunReconciliationJob::dispatchSync(
        RunReconciliationJob::MODE_EXCEPTION_QUEUE,
        [],
        $this->admin->id,
        $token,
    );

    expect(ReconciliationSnapshot::query()->count())->toBe(0)
        ->and(RunReconciliationJob::uiRunStatus(Cache::get(RunReconciliationJob::uiRunCacheKey($token))))
        ->toBe(RunReconciliationJob::UI_RUN_STATUS_COMPLETED);

    Notification::assertSentTo(
        $this->admin,
        ReconciliationRunCompletedNotification::class,
        fn(ReconciliationRunCompletedNotification $notification): bool => $notification->mode === RunReconciliationJob::MODE_EXCEPTION_QUEUE,
    );
});
