<?php

declare(strict_types=1);

use App\Filament\Tenant\Pages\DisbursementsPage;
use App\Filament\Tenant\Pages\MessagesInboxPage;
use App\Filament\Tenant\Resources\MemberRequests\Pages\ListMemberRequests;
use App\Filament\Tenant\Resources\SupportRequests\Pages\ListSupportRequests;
use App\Models\Tenant\Loan;
use App\Models\Tenant\Member;
use App\Models\Tenant\MemberRequest;
use App\Models\Tenant\SupportRequest;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Support\MemberNumberSettings;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Filament::setCurrentPanel('tenant');

    MemberNumberSettings::save([
        'format' => MemberNumberSettings::FORMAT_SEQUENTIAL,
    ]);

    $this->admin = User::create([
        'name' => 'Sort Admin',
        'email' => 'member-number-sort@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $this->actingAs($this->admin, 'tenant');
});

test('messages inbox table sorts member numbers numerically', function () {
    $accounting = app(AccountingService::class);

    $elevenUser = User::create([
        'name' => 'Inbox Eleven User',
        'email' => 'inbox-eleven@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
    ]);

    $twoUser = User::create([
        'name' => 'Inbox Two User',
        'email' => 'inbox-two@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
    ]);

    $eleven = Member::create([
        'user_id' => $elevenUser->id,
        'member_number' => '11',
        'name' => 'Inbox Eleven',
        'monthly_contribution_amount' => 500,
        'joined_at' => Carbon::parse('2024-01-01'),
        'status' => 'active',
    ]);
    $accounting->createMemberAccounts($eleven);

    $two = Member::create([
        'user_id' => $twoUser->id,
        'member_number' => '2',
        'name' => 'Inbox Two',
        'monthly_contribution_amount' => 500,
        'joined_at' => Carbon::parse('2024-01-01'),
        'status' => 'active',
    ]);
    $accounting->createMemberAccounts($two);

    Livewire::test(MessagesInboxPage::class)
        ->sortTable('member_number', 'asc')
        ->assertCanSeeTableRecords([$two, $eleven], inOrder: true)
        ->sortTable('member_number', 'desc')
        ->assertCanSeeTableRecords([$eleven, $two], inOrder: true);
});

test('support requests table sorts member numbers numerically', function () {
    $accounting = app(AccountingService::class);

    $elevenMember = Member::create([
        'member_number' => '11',
        'name' => 'Support Eleven',
        'monthly_contribution_amount' => 500,
        'joined_at' => Carbon::parse('2024-01-01'),
        'status' => 'active',
    ]);
    $accounting->createMemberAccounts($elevenMember);

    $twoMember = Member::create([
        'member_number' => '2',
        'name' => 'Support Two',
        'monthly_contribution_amount' => 500,
        'joined_at' => Carbon::parse('2024-01-01'),
        'status' => 'active',
    ]);
    $accounting->createMemberAccounts($twoMember);

    $eleven = SupportRequest::query()->create([
        'user_id' => $this->admin->id,
        'member_id' => $elevenMember->id,
        'category' => SupportRequest::CATEGORY_OTHER,
        'subject' => 'Eleven ticket',
        'message' => 'Eleven body',
    ]);

    $two = SupportRequest::query()->create([
        'user_id' => $this->admin->id,
        'member_id' => $twoMember->id,
        'category' => SupportRequest::CATEGORY_OTHER,
        'subject' => 'Two ticket',
        'message' => 'Two body',
    ]);

    Livewire::test(ListSupportRequests::class)
        ->sortTable('member.member_number', 'asc')
        ->assertCanSeeTableRecords([$two, $eleven], inOrder: true)
        ->sortTable('member.member_number', 'desc')
        ->assertCanSeeTableRecords([$eleven, $two], inOrder: true);
});

test('member requests table sorts requester member numbers numerically', function () {
    $accounting = app(AccountingService::class);

    $elevenMember = Member::create([
        'member_number' => '11',
        'name' => 'Request Eleven',
        'monthly_contribution_amount' => 500,
        'joined_at' => Carbon::parse('2024-01-01'),
        'status' => 'active',
    ]);
    $accounting->createMemberAccounts($elevenMember);

    $twoMember = Member::create([
        'member_number' => '2',
        'name' => 'Request Two',
        'monthly_contribution_amount' => 500,
        'joined_at' => Carbon::parse('2024-01-01'),
        'status' => 'active',
    ]);
    $accounting->createMemberAccounts($twoMember);

    $eleven = MemberRequest::query()->create([
        'requester_member_id' => $elevenMember->id,
        'type' => MemberRequest::TYPE_ADD_DEPENDENT,
        'status' => MemberRequest::STATUS_PENDING,
        'payload' => ['details' => 'Eleven request'],
    ]);

    $two = MemberRequest::query()->create([
        'requester_member_id' => $twoMember->id,
        'type' => MemberRequest::TYPE_ADD_DEPENDENT,
        'status' => MemberRequest::STATUS_PENDING,
        'payload' => ['details' => 'Two request'],
    ]);

    Livewire::test(ListMemberRequests::class)
        ->sortTable('requester.member_number', 'asc')
        ->assertCanSeeTableRecords([$two, $eleven], inOrder: true)
        ->sortTable('requester.member_number', 'desc')
        ->assertCanSeeTableRecords([$eleven, $two], inOrder: true);
});

test('disbursements table sorts member numbers numerically', function () {
    $accounting = app(AccountingService::class);

    $elevenMember = Member::create([
        'member_number' => '11',
        'name' => 'Disburse Eleven',
        'monthly_contribution_amount' => 500,
        'joined_at' => Carbon::parse('2024-01-01'),
        'status' => 'active',
    ]);
    $accounting->createMemberAccounts($elevenMember);

    $twoMember = Member::create([
        'member_number' => '2',
        'name' => 'Disburse Two',
        'monthly_contribution_amount' => 500,
        'joined_at' => Carbon::parse('2024-01-01'),
        'status' => 'active',
    ]);
    $accounting->createMemberAccounts($twoMember);

    $eleven = Loan::create([
        'member_id' => $elevenMember->id,
        'amount' => 6000,
        'amount_requested' => 6000,
        'amount_approved' => 6000,
        'amount_disbursed' => 0,
        'interest_rate' => 10,
        'term_months' => 6,
        'monthly_repayment' => 1000,
        'total_repaid' => 0,
        'status' => 'approved',
        'applied_at' => Carbon::parse('2026-01-01'),
    ]);

    $two = Loan::create([
        'member_id' => $twoMember->id,
        'amount' => 6000,
        'amount_requested' => 6000,
        'amount_approved' => 6000,
        'amount_disbursed' => 0,
        'interest_rate' => 10,
        'term_months' => 6,
        'monthly_repayment' => 1000,
        'total_repaid' => 0,
        'status' => 'approved',
        'applied_at' => Carbon::parse('2026-01-01'),
    ]);

    Livewire::test(DisbursementsPage::class)
        ->sortTable('member.member_number', 'asc')
        ->assertCanSeeTableRecords([$two, $eleven], inOrder: true)
        ->sortTable('member.member_number', 'desc')
        ->assertCanSeeTableRecords([$eleven, $two], inOrder: true);
});
