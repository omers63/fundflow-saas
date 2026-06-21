<?php

use App\Models\Tenant\Account;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\DirectMessage;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\LoanRepayment;
use App\Models\Tenant\Member;
use App\Models\Tenant\MonthlyStatement;
use App\Models\Tenant\Setting;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\MemberPortalInsightsService;
use App\Support\Insights\InsightFormatter;
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
        'notice',
        'pending_actions',
        'cash_card',
        'fund_card',
        'loan_panel',
        'eligibility_panel',
        'expandable',
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
        'recent_activity',
        'recent_contributions',
        'relation_summaries',
        'household',
        'quick_links',
    ])
        ->and($snapshot['member']['number'])->toBe('MEM-INS01')
        ->and($snapshot['cash_card'])->toHaveKeys(['balance', 'details_url', 'actions'])
        ->and($snapshot['fund_card'])->toHaveKeys(['balance', 'headroom_label', 'details_url'])
        ->and($snapshot['eligibility_panel'])->not->toBeNull()
        ->and($snapshot['loan_panel'])->toBeNull()
        ->and($snapshot['expandable'])->toHaveKeys(['insights', 'household', 'guarantor'])
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

test('member portal insights show lifetime contribution and repayment totals in money format', function () {
    $loan = Loan::query()->create([
        'member_id' => $this->member->id,
        'amount' => 10_000,
        'amount_requested' => 10_000,
        'amount_approved' => 10_000,
        'amount_disbursed' => 10_000,
        'interest_rate' => 0,
        'term_months' => 10,
        'monthly_repayment' => 1000,
        'total_repaid' => 0,
        'status' => 'active',
        'applied_at' => now()->subMonth(),
        'approved_at' => now()->subMonth(),
        'disbursed_at' => now()->subMonth(),
    ]);

    LoanRepayment::query()->create([
        'loan_id' => $loan->id,
        'amount' => 1_250,
        'paid_at' => now()->subDays(10),
    ]);
    LoanRepayment::query()->create([
        'loan_id' => $loan->id,
        'amount' => 2_750,
        'paid_at' => now()->subDays(3),
    ]);

    auth('tenant')->login($this->memberUser);

    $snapshot = app(MemberPortalInsightsService::class)->snapshot();
    $contributionsKpi = collect($snapshot['kpis'])->firstWhere('label', __('Lifetime contributions'));
    $repaidKpi = collect($snapshot['kpis'])->firstWhere('label', __('Lifetime repaid'));
    $inflowKpi = collect($snapshot['kpis'])->firstWhere('label', __('Total fund inflow'));

    expect($contributionsKpi)->not->toBeNull()
        ->and($contributionsKpi['sub'])->toContain(InsightFormatter::money(500.0))
        ->and($repaidKpi)->not->toBeNull()
        ->and($repaidKpi['sub'])->toBe(InsightFormatter::money(4_000.0))
        ->and($inflowKpi)->not->toBeNull()
        ->and($inflowKpi['sub'])->toBe(InsightFormatter::money(4_500.0));

    $stats = collect($snapshot['expandable']['insights']['stats']);
    $contributionTotalStat = $stats->firstWhere('label', __('Contribution total'));
    $loanRepaymentsStat = $stats->firstWhere('label', __('Loan Repayments Total'));
    $collectionTotalStat = $stats->firstWhere('label', __('Collection Total'));

    expect($contributionTotalStat['amount'] ?? null)->toBe(500.0)
        ->and($loanRepaymentsStat['amount'] ?? null)->toBe(4_000.0)
        ->and($collectionTotalStat['amount'] ?? null)->toBe(4_500.0);
});

test('member portal emi due notice embeds amount with symbol before digits', function () {
    $loan = Loan::query()->create([
        'member_id' => $this->member->id,
        'amount' => 20_000,
        'amount_requested' => 20_000,
        'amount_approved' => 20_000,
        'amount_disbursed' => 20_000,
        'interest_rate' => 0,
        'term_months' => 10,
        'monthly_repayment' => 2000,
        'total_repaid' => 0,
        'status' => 'active',
        'applied_at' => now()->subMonth(),
        'approved_at' => now()->subMonth(),
        'disbursed_at' => now()->subMonth(),
    ]);

    LoanInstallment::query()->create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 2000,
        'due_date' => now()->addDays(7),
        'status' => 'pending',
    ]);

    Setting::set('general', 'currency', 'SAR');

    auth('tenant')->login($this->memberUser);
    app()->setLocale('ar');

    $snapshot = app(MemberPortalInsightsService::class)->snapshot();
    $body = (string) ($snapshot['notice']['body'] ?? '');

    expect($snapshot['notice']['title'] ?? null)->toBe(__('EMI payment due soon'))
        ->and($body)->toContain('ff-member-amount')
        ->and($body)->toMatch('/ff-sar-symbol[^>]*>.*<\/span><span class="ff-member-amount__digits">2,000\.00<\/span>/s');
});

test('member portal insights render negative fund and inflow values in red', function () {
    $this->member->cashAccount()->update(['balance' => -700]);
    $this->member->fundAccount()->update(['balance' => -700]);

    auth('tenant')->login($this->memberUser);

    $snapshot = app(MemberPortalInsightsService::class)->snapshot();
    $fundKpi = collect($snapshot['kpis'])->firstWhere('label', __('Fund'));
    $inflowKpi = collect($snapshot['kpis'])->firstWhere('label', __('Total fund inflow'));

    expect($fundKpi)->not->toBeNull()
        ->and($fundKpi['value'])->toStartWith('-')
        ->and($fundKpi['accent'])->toBe('rose')
        ->and($fundKpi['value_class'])->toContain('text-rose-600')
        ->and($inflowKpi)->not->toBeNull()
        ->and($inflowKpi['value'])->toStartWith('-')
        ->and($inflowKpi['accent'])->toBe('rose')
        ->and($inflowKpi['value_class'])->toContain('text-rose-600');
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

test('member portal insights formats latest statement period from Y-m string', function () {
    MonthlyStatement::create([
        'member_id' => $this->member->id,
        'period' => '2026-01',
        'opening_balance' => 0,
        'total_contributions' => 500,
        'total_repayments' => 0,
        'closing_balance' => 500,
        'generated_at' => now(),
    ]);

    auth('tenant')->login($this->memberUser);
    app()->setLocale('en');

    $snapshot = app(MemberPortalInsightsService::class)->snapshot();

    expect($snapshot['latest_statement']['period'] ?? null)->toBe('January 2026');
});
