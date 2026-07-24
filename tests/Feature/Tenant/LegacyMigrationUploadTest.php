<?php

declare(strict_types=1);

use App\Filament\Tenant\Pages\LegacyMigrationPage;
use App\Models\Tenant\Setting;
use App\Models\Tenant\User;
use App\Services\LegacyMigration\LegacyMigrationWorkingCopy;
use App\Services\LegacyMigration\LegacyPaymentClassifierService;
use App\Support\AssociativeCsv;
use App\Support\FilamentStoredUploadPath;
use App\Support\LegacyMigrationUploadDiagnostics;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    $this->admin = User::create([
        'name' => 'Upload Admin',
        'email' => 'upload-admin@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);
});

test('legacy migration upload diagnostics detects loan_id column', function () {
    Storage::disk('local')->put(LegacyMigrationWorkingCopy::LOANS_RELATIVE, implode("\n", [
        'loan_id,member_number,amount_approved,disbursed_at',
        '94,58,20000,2020-07-10',
    ]));

    $summary = app(LegacyMigrationUploadDiagnostics::class)->summarize();

    expect($summary['loans']['has_loan_id'] ?? false)->toBeTrue()
        ->and($summary['loans']['row_count'] ?? 0)->toBe(1);

    Storage::disk('local')->delete(LegacyMigrationWorkingCopy::LOANS_RELATIVE);
});

test('legacy migration working copy overwrites loans file on snapshot', function () {
    $service = app(LegacyMigrationWorkingCopy::class);

    $first = storage_path('app/legacy-upload-test-first.csv');
    $second = storage_path('app/legacy-upload-test-second.csv');

    file_put_contents($first, "loan_status,member_number,amount_approved,disbursed_at\n,58,20000,2020-07-10\n");
    file_put_contents($second, "loan_id,loan_status,member_number,amount_approved,disbursed_at\n94,,58,20000,2020-07-10\n");

    $service->snapshot(['loans' => $first]);
    expect(in_array('loan_id', AssociativeCsv::headers(Storage::disk('local')->path(LegacyMigrationWorkingCopy::LOANS_RELATIVE)), true))->toBeFalse();

    $service->snapshot(['loans' => $second]);
    expect(in_array('loan_id', AssociativeCsv::headers(Storage::disk('local')->path(LegacyMigrationWorkingCopy::LOANS_RELATIVE)), true))->toBeTrue();

    @unlink($first);
    @unlink($second);
    Storage::disk('local')->delete(LegacyMigrationWorkingCopy::LOANS_RELATIVE);
});

test('legacy migration working copy overwrites members file on snapshot', function () {
    $service = app(LegacyMigrationWorkingCopy::class);

    $first = storage_path('app/legacy-upload-members-first.csv');
    $second = storage_path('app/legacy-upload-members-second.csv');

    file_put_contents($first, "member_number,name,email,parent_member_number\n1,Parent,parent@fund.test,\n");
    file_put_contents($second, "member_number,name,email\n1,Parent,parent@fund.test\n");

    $service->snapshot(['members' => $first]);
    expect(in_array('parent_member_number', AssociativeCsv::headers(Storage::disk('local')->path(LegacyMigrationWorkingCopy::MEMBERS_RELATIVE)), true))->toBeTrue();

    $service->snapshot(['members' => $second]);
    expect(in_array('parent_member_number', AssociativeCsv::headers(Storage::disk('local')->path(LegacyMigrationWorkingCopy::MEMBERS_RELATIVE)), true))->toBeFalse();

    @unlink($first);
    @unlink($second);
    Storage::disk('local')->delete(LegacyMigrationWorkingCopy::MEMBERS_RELATIVE);
});

test('filament stored upload path resolves livewire temporary file references', function () {
    $filename = 'legacy-upload-livewire-temp.csv';
    $temporaryPath = FileUploadConfiguration::path($filename);

    FileUploadConfiguration::storage()->put($temporaryPath, "member_number,name,email\n1,Temp,temp@fund.test\n");

    $resolved = FilamentStoredUploadPath::tryResolveReadableCsvToAbsolutePath([
        'livewire-file:'.$filename,
    ]);

    expect($resolved)->not->toBeNull()
        ->and(is_readable($resolved['absolutePath']))->toBeTrue()
        ->and(file_get_contents($resolved['absolutePath']))->toContain('temp@fund.test');

    FileUploadConfiguration::storage()->delete($temporaryPath);
});

test('legacy migration livewire upload writes working members file', function () {
    Filament::setCurrentPanel('tenant');

    $file = UploadedFile::fake()->createWithContent('members.csv', implode("\n", [
        'member_number,name,email',
        '1,Fresh,fresh@fund.test',
    ]));

    Livewire::actingAs($this->admin, 'tenant')
        ->test(LegacyMigrationPage::class, ['embedded' => true])
        ->set('pendingMembersCsv', $file)
        ->assertNotified(__('CSV uploaded'));

    expect(Storage::disk('local')->exists(LegacyMigrationWorkingCopy::MEMBERS_RELATIVE))->toBeTrue()
        ->and(AssociativeCsv::read(Storage::disk('local')->path(LegacyMigrationWorkingCopy::MEMBERS_RELATIVE)))->toHaveCount(1);

    Storage::disk('local')->delete(LegacyMigrationWorkingCopy::MEMBERS_RELATIVE);
});

test('legacy migration loans step renders loans and payments upload cards', function () {
    Filament::setCurrentPanel('tenant');

    Setting::set('legacy_migration', 'members_imported', '1');

    Livewire::actingAs($this->admin, 'tenant')
        ->test(LegacyMigrationPage::class, ['embedded' => true])
        ->call('goToStep', 2)
        ->assertSuccessful()
        ->assertSee(__('Step 2: Import loans'), false)
        ->assertSee(__('Loans CSV'), false)
        ->assertSee(__('Payments CSV'), false);
});

test('legacy migration apply step renders without markup errors', function () {
    Filament::setCurrentPanel('tenant');

    Setting::set('legacy_migration', 'members_imported', '1');
    Setting::set('legacy_migration', 'loans_imported', '1');
    Storage::disk('local')->put(
        LegacyPaymentClassifierService::CLASSIFIED_PAYMENTS_DISK_PATH,
        "member_number,amount,date,payment_type\n1,100,2025-01-01,contribution\n",
    );

    Livewire::actingAs($this->admin, 'tenant')
        ->test(LegacyMigrationPage::class, ['embedded' => true])
        ->call('goToStep', 5)
        ->assertSuccessful()
        ->assertSee(__('Step 5: Apply migration'), false);

    Storage::disk('local')->delete(LegacyPaymentClassifierService::CLASSIFIED_PAYMENTS_DISK_PATH);
});

test('legacy migration import members reads working copy from disk', function () {
    Filament::setCurrentPanel('tenant');

    Storage::disk('local')->put(LegacyMigrationWorkingCopy::MEMBERS_RELATIVE, implode("\n", [
        'member_number,name,email',
        '1,Fresh,fresh@fund.test',
    ]));

    Livewire::actingAs($this->admin, 'tenant')
        ->test(LegacyMigrationPage::class, ['embedded' => true])
        ->fillForm([
            'cutoff_date' => '2025-12-31',
            'default_password' => 'password123',
        ])
        ->call('importMembers')
        ->assertNotified(__('Members imported'));

    Storage::disk('local')->delete(LegacyMigrationWorkingCopy::MEMBERS_RELATIVE);
});
