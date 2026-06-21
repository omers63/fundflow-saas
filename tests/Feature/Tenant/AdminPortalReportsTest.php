<?php

declare(strict_types=1);

use App\Filament\Tenant\Pages\ReportsPage;
use App\Models\Tenant\Loan;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Services\Members\GuarantorExposureExportService;
use App\Services\Tenant\TenantAdminReportExportService;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Filament::setCurrentPanel('tenant');
});

test('tenant admin can export collections report as csv from reports page', function () {
    $admin = User::create([
        'name' => 'Reports Admin',
        'email' => 'reports-admin@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    Livewire::actingAs($admin, 'tenant')
        ->test(ReportsPage::class)
        ->set('reportType', 'collections')
        ->set('reportFormat', 'csv')
        ->call('generateCustomReport')
        ->assertSuccessful();
});

test('guarantor exposure export includes guaranteed loan rows', function () {
    $guarantor = Member::factory()->create();
    $borrower = Member::factory()->create();

    Loan::factory()->create([
        'member_id' => $borrower->id,
        'guarantor_member_id' => $guarantor->id,
        'status' => 'active',
        'amount_approved' => 3000,
        'amount_disbursed' => 3000,
    ]);

    $response = app(GuarantorExposureExportService::class)->downloadCsv();
    ob_start();
    $response->sendContent();
    $csv = (string) ob_get_clean();

    expect($response->headers->get('content-type'))->toContain('text/csv')
        ->and($csv)->toContain('guarantor_member_number')
        ->and($csv)->toContain((string) $guarantor->member_number);
});

test('non-admin cannot export custom reports', function () {
    $user = User::create([
        'name' => 'Reports Staff',
        'email' => 'reports-staff@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
    ]);

    $this->actingAs($user, 'tenant');

    app(TenantAdminReportExportService::class)->download('audit', 'csv');
})->throws(HttpException::class);
