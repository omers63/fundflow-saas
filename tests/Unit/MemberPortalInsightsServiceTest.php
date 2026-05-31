<?php

use App\Models\Tenant\Account;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\DirectMessage;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\MemberPortalInsightsService;
use App\Support\Tenant\CurrentMember;
use Filament\Facades\Filament;
use Tests\Concerns\InitializesTenancy;
use Tests\TestCase;

uses(TestCase::class, InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Filament::setCurrentPanel('member');

    Account::query()->delete();
    Contribution::query()->delete();
    DirectMessage::query()->delete();
    Member::query()->delete();
    User::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 0, 'is_master' => true]);

    $this->admin = User::create([
        'name' => 'Admin',
        'email' => 'admin@insights.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $this->memberUser = User::create([
        'name' => 'Insights Member',
        'email' => 'member@insights.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
    ]);

    $this->member = Member::create([
        'user_id' => $this->memberUser->id,
        'member_number' => 'MEM-INS01',
        'name' => 'Insights Member',
        'email' => 'member@insights.test',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($this->member);

    Contribution::create([
        'member_id' => $this->member->id,
        'period' => now()->subMonth()->startOfMonth(),
        'amount' => 500,
        'status' => 'posted',
        'posted_at' => now(),
    ]);
});

test('member portal insights snapshot includes greeting and kpis', function () {
    auth('tenant')->login($this->memberUser);

    $snapshot = app(MemberPortalInsightsService::class)->snapshot(CurrentMember::get());

    expect($snapshot)->toHaveKeys([
        'greeting',
        'hero',
        'kpis',
        'member',
        'quick_actions',
        'sparkline',
        'steps',
        'cycle',
        'arrears',
        'fund_summary',
        'trend',
        'trend_max',
        'recent_activity',
        'recent_contributions',
        'relation_summaries',
        'household',
        'quick_links',
    ])
        ->and($snapshot['member']['number'])->toBe('MEM-INS01')
        ->and($snapshot['kpis'])->toHaveCount(6)
        ->and($snapshot['greeting'])->toHaveKeys([
            'period_label',
            'first_name',
            'name',
            'fund_name',
            'date',
            'subtitle',
            'avatar_url',
            'initials',
            'profile_url',
            'balances',
            'pills',
        ])
        ->and($snapshot['greeting']['balances'])->toHaveCount(2)
        ->and($snapshot['steps'])->not->toBeEmpty()
        ->and($snapshot['trend'])->toHaveCount(6);
});

test('member portal insights counts unread admin messages', function () {
    DirectMessage::create([
        'from_user_id' => $this->admin->id,
        'to_user_id' => $this->memberUser->id,
        'subject' => 'Welcome',
        'body' => 'Hello from admin',
    ]);

    auth('tenant')->login($this->memberUser);

    $snapshot = app(MemberPortalInsightsService::class)->snapshot();

    $messagesKpi = collect($snapshot['kpis'])->firstWhere('label', __('Messages'));

    expect($messagesKpi['value'])->toBe('1');
});
