<?php

declare(strict_types=1);

use App\Filament\Tenant\Resources\Contributions\ContributionResource;
use App\Filament\Tenant\Resources\Contributions\Pages\ListContributions;
use App\Models\Central\Tenant;
use App\Models\Tenant\Account;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\ContributionExportService;
use App\Services\ContributionImportService;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Filament::setCurrentPanel('tenant');

    $tenant = Tenant::find('testing');
    $this->domain = 'testing.localhost';

    if (! $tenant->domains()->where('domain', $this->domain)->exists()) {
        $tenant->domains()->create(['domain' => $this->domain]);
    }

    $this->admin = User::create([
        'name' => 'Contribution Import Admin',
        'email' => 'contribution-import@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $this->actingAs($this->admin, 'tenant');

    Account::query()->delete();
    Contribution::query()->forceDelete();
    Member::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 500_000, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 500_000, 'is_master' => true]);
    Account::create(['type' => 'fees', 'name' => 'Master Fees', 'balance' => 0, 'is_master' => true]);

    $this->accounting = app(AccountingService::class);
});

function createContributionImportMember(AccountingService $accounting, string $email, float $monthly = 5000): Member
{
    $member = Member::create([
        'member_number' => 'MEM-'.uniqid(),
        'name' => 'Import Member '.substr($email, 0, 8),
        'email' => $email,
        'monthly_contribution_amount' => $monthly,
        'joined_at' => now()->subMonths(18),
        'status' => 'active',
    ]);
    $accounting->createMemberAccounts($member);

    return $member->fresh();
}

function writeContributionImportCsv(string $contents): string
{
    $path = sys_get_temp_dir().'/contribution-import-'.uniqid('', true).'.csv';
    file_put_contents($path, $contents);

    return $path;
}

test('contribution import creates pending row from csv', function () {
    $member = createContributionImportMember($this->accounting, 'pending-contrib@example.test');

    $csv = <<<CSV
member_email,period,amount,status
{$member->email},2025-03,4500,pending
CSV;

    $result = app(ContributionImportService::class)->import(writeContributionImportCsv($csv));

    expect($result['created'])->toBe(1)
        ->and($result['failed'])->toBe(0);

    $contribution = Contribution::query()->where('member_id', $member->id)->first();

    expect($contribution)->not->toBeNull()
        ->and($contribution->status)->toBe('pending')
        ->and((float) $contribution->amount)->toBe(4500.0)
        ->and($contribution->payment_method)->toBe(Contribution::PAYMENT_METHOD_IMPORT_CSV);
});

test('contribution import posts fund leg for posted status without cash debit', function () {
    $member = createContributionImportMember($this->accounting, 'posted-contrib@example.test');

    $csv = <<<CSV
member_email,period,amount,status,posted_at
{$member->email},2025-04,5000,posted,2025-04-10 09:00:00
CSV;

    $result = app(ContributionImportService::class)->import(writeContributionImportCsv($csv));

    expect($result)->toMatchArray(['created' => 1, 'failed' => 0]);

    $contribution = Contribution::query()->where('member_id', $member->id)->firstOrFail();

    expect($contribution->status)->toBe('posted')
        ->and((float) $contribution->amount_collected)->toBe(5000.0)
        ->and($contribution->posted_at?->toDateTimeString())->toBe('2025-04-10 09:00:00');

    $member->refresh()->load('cashAccount', 'fundAccount');
    expect((float) $member->cashAccount->fresh()->balance)->toBe(0.0)
        ->and((float) $member->fundAccount->fresh()->balance)->toBe(5000.0);
});

test('contribution export streams csv with round-trip headers', function () {
    $member = createContributionImportMember($this->accounting, 'export-contrib@example.test');

    Contribution::create([
        'member_id' => $member->id,
        'period' => '2025-05-01',
        'amount' => 5000,
        'amount_due' => 5000,
        'amount_collected' => 0,
        'status' => 'pending',
        'payment_method' => Contribution::PAYMENT_METHOD_ADMIN,
    ]);

    ob_start();
    app(ContributionExportService::class)->downloadCsv()->sendContent();
    $csv = ob_get_clean();

    expect($csv)->toContain('member_number')
        ->and($csv)->toContain($member->member_number)
        ->and($csv)->toContain('2025-05-01');
});

test('contributions tab exposes import and export header actions', function () {
    Livewire::test(ListContributions::class)
        ->set('activeTab', 'ledger')
        ->assertSuccessful()
        ->assertTableActionExists('importContributions')
        ->assertTableActionExists('exportContributions')
        ->assertTableActionExists('create');

    Livewire::test(ListContributions::class)
        ->set('activeTab', 'cycle')
        ->assertTableActionDoesNotExist('importContributions')
        ->assertTableActionDoesNotExist('exportContributions');
});

test('legacy ledger tab alias resolves to contributions tab', function () {
    expect(ContributionResource::normalizeListTab('ledger'))->toBe('contributions')
        ->and(ContributionResource::listUrl('ledger'))
        ->toBe(ContributionResource::listUrl('contributions'));
});
