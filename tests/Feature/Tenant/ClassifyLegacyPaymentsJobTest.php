<?php

declare(strict_types=1);

use App\Jobs\Tenant\ClassifyLegacyPaymentsJob;
use App\Models\Tenant\Setting;
use App\Models\Tenant\User;
use App\Services\LegacyMigration\LegacyPaymentClassifierService;
use App\Support\AssociativeCsv;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    User::query()->delete();
    Setting::query()->where('group', 'legacy_migration')->delete();

    $this->admin = User::create([
        'name' => 'Classify Job Admin',
        'email' => 'classify-job@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);
});

test('classify legacy payments job writes classified csv and stores stats', function () {
    $this->actingAs($this->admin, 'tenant');

    $membersPath = storage_path('app/classify-job-members.csv');
    $loansPath = storage_path('app/classify-job-loans.csv');
    $paymentsPath = storage_path('app/classify-job-payments.csv');

    AssociativeCsv::write($membersPath, ['member_number', 'name', 'email', 'monthly_contribution_amount'], [
        ['1', 'Job Member', 'job-member@fund.test', '1000'],
    ]);
    AssociativeCsv::write($loansPath, ['member_number', 'amount_approved', 'disbursed_at', 'loan_status'], []);
    AssociativeCsv::write($paymentsPath, ['member_number', 'payment_date', 'amount'], [
        ['1', '2025-10-01', '1000'],
    ]);

    ClassifyLegacyPaymentsJob::dispatchSync([
        'cutoff_date' => '2025-12-31',
        'default_password' => 'password12345',
        'members_path' => $membersPath,
        'loans_path' => $loansPath,
        'payments_path' => $paymentsPath,
        'strategy' => 'historical',
    ], $this->admin->id);

    expect(Setting::get('legacy_migration', 'classify_status'))->toBe('completed')
        ->and(json_decode((string) Setting::get('legacy_migration', 'classify_stats'), true)['contributions'] ?? 0)->toBe(1);

    expect(file_exists(storage_path('app/'.LegacyPaymentClassifierService::CLASSIFIED_PAYMENTS_DISK_PATH)))->toBeTrue();

    @unlink($membersPath);
    @unlink($loansPath);
    @unlink($paymentsPath);
    @unlink(storage_path('app/'.LegacyPaymentClassifierService::CLASSIFIED_PAYMENTS_DISK_PATH));
});
