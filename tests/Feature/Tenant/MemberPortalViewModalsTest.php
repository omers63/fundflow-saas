<?php

declare(strict_types=1);

use App\Filament\Support\ViewActions\ViewAccountTransactionAction;
use App\Filament\Support\ViewActions\ViewFundPostingAction;
use App\Models\Tenant\FundPosting;
use App\Models\Tenant\Member;
use App\Models\Tenant\Transaction;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use Illuminate\Support\Facades\Blade;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    $this->member = Member::factory()->create();
    app(AccountingService::class)->createMemberAccounts($this->member);

    $this->memberUser = User::create([
        'name' => $this->member->name,
        'email' => 'modal-user-'.uniqid().'@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
    ]);

    $this->member->update(['user_id' => $this->memberUser->id]);
});

test('member portal transaction modal sections use compact prototype layout keys', function () {
    $account = $this->member->cashAccount;

    $transaction = Transaction::factory()->for($account)->create([
        'type' => 'credit',
        'amount' => 500,
        'balance_after' => 500,
        'description' => 'Bank deposit received',
        'transacted_at' => now(),
    ]);

    $sections = ViewAccountTransactionAction::memberPortalSections($transaction);

    expect($sections)->toHaveCount(2)
        ->and($sections[0])->toHaveKey('hero')
        ->and($sections[0]['hero']['chip'])->toBe(__('Credit'))
        ->and($sections[0]['hero']['subtitle'])->toBe(__('Cash account'))
        ->and($sections[1]['columns'])->toBe(3);

    $html = Blade::render(
        view('filament.member.partials.view-record-modal', ['sections' => $sections])->render()
    );

    expect($html)
        ->toContain('ff-member-record-modal')
        ->toContain('ff-member-record-modal__hero')
        ->toContain('Bank deposit received');
});

test('member portal deposit modal sections include status chip and posting details', function () {
    $posting = FundPosting::create([
        'member_id' => $this->member->id,
        'posting_date' => now()->toDateString(),
        'amount' => 1200,
        'status' => 'pending',
        'reference' => 'REF-001',
        'comments' => 'Salary transfer',
    ]);

    $sections = ViewFundPostingAction::memberPortalSections($posting);

    expect($sections[0]['hero']['chipVariant'])->toBe('amber')
        ->and($sections[1]['items'])->toHaveCount(3);

    $html = Blade::render(
        view('filament.member.partials.view-record-modal', ['sections' => $sections])->render()
    );

    expect($html)
        ->toContain('ff-member-detail-grid--3col')
        ->toContain('Salary transfer');
});
