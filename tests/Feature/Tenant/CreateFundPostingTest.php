<?php

declare(strict_types=1);

use App\Filament\Tenant\Resources\FundPostings\FundPostingResource;
use App\Filament\Tenant\Resources\FundPostings\Pages\CreateFundPosting;
use App\Filament\Tenant\Resources\FundPostings\Pages\ListFundPostings;
use App\Models\Tenant\Account;
use App\Models\Tenant\FundPosting;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Filament::setCurrentPanel('tenant');

    Account::query()->delete();
    Member::query()->delete();
    FundPosting::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fees', 'name' => 'Master Fees', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'bank', 'name' => 'Master Bank', 'balance' => 0, 'is_master' => true]);

    $this->admin = User::create([
        'name' => 'Fund Admin',
        'email' => 'admin-create-deposit@test.com',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $this->member = Member::create([
        'member_number' => 'MEM-0099',
        'name' => 'Deposit Target',
        'monthly_contribution_amount' => 0,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($this->member);
});

test('deposits list shows new deposit header action', function () {
    Livewire::actingAs($this->admin, 'tenant')
        ->test(ListFundPostings::class)
        ->assertSuccessful()
        ->assertSee(__('New deposit'));
});

test('admin can create and auto-approve a deposit for any member', function () {
    Notification::fake();

    Livewire::actingAs($this->admin, 'tenant')
        ->test(CreateFundPosting::class)
        ->fillForm([
            'member_id' => $this->member->id,
            'posting_date' => '2026-05-10',
            'amount' => 2500,
            'reference' => 'ADMIN-TXN-1',
            'comments' => 'Admin-initiated deposit',
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified()
        ->assertRedirect(FundPostingResource::getUrl('index'));

    $posting = FundPosting::query()->where('member_id', $this->member->id)->first();

    expect($posting)->not->toBeNull()
        ->and($posting->status)->toBe('accepted')
        ->and($posting->reviewed_by)->toBe($this->admin->id)
        ->and($posting->reviewed_at)->not->toBeNull()
        ->and($posting->amount)->toBe('2500.00')
        ->and($posting->reference)->toBe('ADMIN-TXN-1')
        ->and($posting->admin_remarks)->toBe('Admin-initiated deposit')
        ->and((float) $this->member->cashAccount->fresh()->balance)->toBe(2500.0)
        ->and((float) Account::masterCash()->balance)->toBe(2500.0);
});

test('create deposit page prefills member from query string', function () {
    Livewire::actingAs($this->admin, 'tenant')
        ->withQueryParams(['member_id' => (string) $this->member->id])
        ->test(CreateFundPosting::class)
        ->assertFormSet([
            'member_id' => $this->member->id,
        ]);
});
