<?php

declare(strict_types=1);

use App\Models\Central\Tenant;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\FundPosting;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Models\Tenant\Transaction;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\MemberActivityFeedService;
use Filament\Facades\Filament;
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
        'member_number' => 'MEM-ACT01',
        'name' => 'Activity Member',
        'email' => 'activity@fund.test',
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
});

test('activity page renders filter chips and transaction table', function () {
    Filament::setCurrentPanel('member');
    $this->actingAs($this->memberUser, 'tenant');

    $posting = FundPosting::create([
        'member_id' => $this->member->id,
        'amount' => 500,
        'status' => 'accepted',
        'posting_date' => now(),
        'reference' => 'Bank ref ACT-01',
    ]);

    Transaction::create([
        'account_id' => $this->member->cashAccount->id,
        'member_id' => $this->member->id,
        'type' => 'credit',
        'amount' => 500,
        'balance_after' => 500,
        'reference_type' => FundPosting::class,
        'reference_id' => $posting->id,
        'description' => 'Deposit accepted',
        'transacted_at' => now(),
    ]);

    $this->get('http://'.$this->domain.'/member/activity')
        ->assertSuccessful()
        ->assertSee('ff-member-activity', false)
        ->assertSee(__('Contributions'), false)
        ->assertSee(__('Deposits'), false)
        ->assertSee(__('Transactions'), false)
        ->assertSee(__('Deposit'), false);
});

test('activity page filter narrows transactions', function () {
    Filament::setCurrentPanel('member');
    $this->actingAs($this->memberUser, 'tenant');

    $posting = FundPosting::create([
        'member_id' => $this->member->id,
        'amount' => 300,
        'status' => 'accepted',
        'posting_date' => now(),
    ]);

    $contribution = Contribution::create([
        'member_id' => $this->member->id,
        'period' => now()->startOfMonth(),
        'amount' => 1000,
        'status' => 'posted',
        'posted_at' => now(),
        'paid_at' => now(),
    ]);

    Transaction::create([
        'account_id' => $this->member->cashAccount->id,
        'member_id' => $this->member->id,
        'type' => 'debit',
        'amount' => 1000,
        'balance_after' => 0,
        'reference_type' => Contribution::class,
        'reference_id' => $contribution->id,
        'description' => 'Contribution posted',
        'transacted_at' => now()->subDay(),
    ]);

    Transaction::create([
        'account_id' => $this->member->cashAccount->id,
        'member_id' => $this->member->id,
        'type' => 'credit',
        'amount' => 300,
        'balance_after' => 300,
        'reference_type' => FundPosting::class,
        'reference_id' => $posting->id,
        'description' => 'Deposit accepted',
        'transacted_at' => now(),
    ]);

    $this->get('http://'.$this->domain.'/member/activity?filter='.MemberActivityFeedService::FILTER_DEPOSITS)
        ->assertSuccessful()
        ->assertSee(__('Deposit'))
        ->assertDontSee(__('Contribution —'));
});

test('contributions list shows stat cards only', function () {
    Filament::setCurrentPanel('member');
    $this->actingAs($this->memberUser, 'tenant');

    $this->get('http://'.$this->domain.'/member/my-contributions')
        ->assertSuccessful()
        ->assertSee('ff-member-contributions-stats', false)
        ->assertSee(__('Total contributed'), false)
        ->assertSee(__('This cycle'), false)
        ->assertSee(__('Cycles missed (12 mo)'), false)
        ->assertSee(__('Cycles exempt (12 mo)'), false)
        ->assertDontSee(__('Open cycle'), false)
        ->assertDontSee(__('Catch up on missed periods'), false);
});
