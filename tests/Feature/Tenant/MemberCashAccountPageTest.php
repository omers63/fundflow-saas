<?php

declare(strict_types=1);

use App\Filament\Member\Pages\CashAccountPage;
use App\Models\Central\Tenant;
use App\Models\Tenant\FundPosting;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Models\Tenant\Transaction;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use Filament\Facades\Filament;
use Livewire\Livewire;
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
        'member_number' => 'MEM-CASH01',
        'name' => 'Cash Page Member',
        'email' => 'cashpage@fund.test',
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

test('cash account page shows balance grid deposit form and ledger widgets', function () {
    Filament::setCurrentPanel('member');
    $this->actingAs($this->memberUser, 'tenant');

    $this->member->cashAccount()->update(['balance' => 2500]);

    Transaction::create([
        'account_id' => $this->member->cashAccount->id,
        'member_id' => $this->member->id,
        'type' => 'credit',
        'amount' => 2500,
        'balance_after' => 2500,
        'description' => 'Opening deposit',
        'transacted_at' => now(),
    ]);

    $this->get('http://'.$this->domain.'/member/cash-account')
        ->assertSuccessful()
        ->assertSee('ff-member-cash-account', false)
        ->assertSee(__('Current balance'), false)
        ->assertSee(__('Bank transfer details'), false)
        ->assertSee(__('Submit a deposit'), false)
        ->assertSee(__('Cash transactions'), false)
        ->assertSee(__('Deposit requests'), false)
        ->assertSee('Opening deposit', false);
});

test('cash account ledger footer summary uses arabic amount label when locale is ar', function () {
    $this->memberUser->update(['preferred_locale' => 'ar']);
    app()->setLocale('ar');
    session()->put('locale', 'ar');

    Filament::setCurrentPanel('member');
    $this->actingAs($this->memberUser->fresh(), 'tenant');

    $this->member->cashAccount()->update(['balance' => 2500]);

    Transaction::create([
        'account_id' => $this->member->cashAccount->id,
        'member_id' => $this->member->id,
        'type' => 'credit',
        'amount' => 2500,
        'balance_after' => 2500,
        'description' => 'Opening deposit',
        'transacted_at' => now(),
    ]);

    $this->get('http://'.$this->domain.'/member/cash-account')
        ->assertSuccessful()
        ->assertSee('المبلغ', false)
        ->assertSee('ff-sar-symbol', false)
        ->assertSee('دائن', false)
        ->assertDontSee('Amount:', false)
        ->assertDontSee('>credit<', false);
});

test('member can submit deposit from cash account page', function () {
    Filament::setCurrentPanel('member');
    $this->actingAs($this->memberUser, 'tenant');

    Livewire::actingAs($this->memberUser, 'tenant')
        ->test(CashAccountPage::class)
        ->fillForm([
            'posting_date' => now()->toDateString(),
            'amount' => 500,
            'reference' => 'TRF-123',
            'comments' => 'Test deposit',
        ], 'depositForm')
        ->call('submitDeposit')
        ->assertHasNoFormErrors();

    expect(FundPosting::query()->where('member_id', $this->member->id)->count())->toBe(1);

    $posting = FundPosting::query()->first();

    expect($posting)->not->toBeNull()
        ->and((float) $posting->amount)->toBe(500.0)
        ->and($posting->status)->toBe('pending');
});
