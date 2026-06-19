<?php

declare(strict_types=1);

use App\Models\Central\Tenant;
use App\Models\Tenant\FundPosting;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Models\Tenant\Transaction;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\MemberActivityFeedService;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    $tenant = Tenant::find('testing');
    $this->domain = 'testing.localhost';

    if (! $tenant->domains()->where('domain', $this->domain)->exists()) {
        $tenant->domains()->create(['domain' => $this->domain]);
    }

    $member = Member::create([
        'member_number' => 'MEM-EXP01',
        'name' => 'Export Member',
        'email' => 'export@fund.test',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($member);

    $this->memberUser = User::create([
        'name' => $member->name,
        'email' => $member->email,
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
    ]);

    $member->update(['user_id' => $this->memberUser->id]);
    $this->member = $member->fresh();

    Setting::set('general', 'currency', 'SAR');

    $posting = FundPosting::create([
        'member_id' => $this->member->id,
        'amount' => 750,
        'status' => 'accepted',
        'posting_date' => now(),
    ]);

    Transaction::create([
        'account_id' => $this->member->cashAccount->id,
        'member_id' => $this->member->id,
        'type' => 'credit',
        'amount' => 750,
        'balance_after' => 750,
        'reference_type' => FundPosting::class,
        'reference_id' => $posting->id,
        'description' => 'Deposit accepted',
        'transacted_at' => now(),
    ]);
});

test('member can download activity csv for date range', function () {
    $from = now()->subDays(7)->toDateString();
    $to = now()->toDateString();

    $this->actingAs($this->memberUser, 'tenant')
        ->get('http://'.$this->domain.'/member/activity/export?from='.$from.'&to='.$to.'&filter='.MemberActivityFeedService::FILTER_ALL)
        ->assertSuccessful()
        ->assertHeader('content-type', 'text/csv; charset=UTF-8')
        ->assertDownload('member-activity-MEM-EXP01-'.$from.'-'.$to.'.csv');
});

test('activity export requires a linked member profile', function () {
    $from = now()->subDays(7)->toDateString();
    $to = now()->toDateString();

    $orphanUser = User::create([
        'name' => 'No Member User',
        'email' => 'nomember@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
    ]);

    $this->actingAs($orphanUser, 'tenant')
        ->get('http://'.$this->domain.'/member/activity/export?from='.$from.'&to='.$to)
        ->assertForbidden();
});

test('member activity csv includes account time and balance columns', function () {
    app()->setLocale('en');

    $at = now()->startOfDay()->addHours(9);

    $posting = FundPosting::create([
        'member_id' => $this->member->id,
        'amount' => 250,
        'status' => 'accepted',
        'posting_date' => $at,
    ]);

    Transaction::create([
        'account_id' => $this->member->fundAccount->id,
        'member_id' => $this->member->id,
        'type' => 'credit',
        'amount' => 250,
        'balance_after' => 250,
        'reference_type' => FundPosting::class,
        'reference_id' => $posting->id,
        'description' => 'Allocation — fund',
        'transacted_at' => $at->copy()->addMinutes(5),
    ]);

    $from = now()->subDays(7)->toDateString();
    $to = now()->toDateString();

    $response = $this->actingAs($this->memberUser, 'tenant')
        ->get('http://'.$this->domain.'/member/activity/export?from='.$from.'&to='.$to.'&filter='.MemberActivityFeedService::FILTER_ALL);

    $response->assertSuccessful();

    $csv = $response->streamedContent();

    expect($csv)
        ->toContain('Account')
        ->toContain('Balance after')
        ->toContain('Time')
        ->toContain('Cash account')
        ->toContain('Fund account')
        ->toContain('09:05:00');
});

test('activity export row mapper includes account and reference details', function () {
    app()->setLocale('en');

    $transaction = Transaction::query()
        ->where('member_id', $this->member->id)
        ->with('account')
        ->firstOrFail();

    $row = app(MemberActivityFeedService::class)->mapExportRow($transaction, 'SAR');

    expect($row[2])->toBe('Cash account')
        ->and($row[8])->toContain('Txn #'.$transaction->id);
});
