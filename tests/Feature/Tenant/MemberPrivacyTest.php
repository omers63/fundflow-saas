<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\Member;
use App\Models\Tenant\MemberPrivacyRequest;
use App\Models\Tenant\User;
use App\Services\Privacy\MemberPrivacyService;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Storage::fake('local');

    Account::query()->delete();
    Member::query()->delete();
    User::query()->delete();
    MemberPrivacyRequest::query()->delete();

    $this->admin = User::create([
        'name' => 'Privacy Admin',
        'email' => 'privacy-admin@test.com',
        'password' => bcrypt('password'),
        'is_admin' => true,
    ]);

    $this->user = User::create([
        'name' => 'Privacy Member',
        'email' => 'privacy-member@test.com',
        'password' => bcrypt('password'),
        'is_admin' => false,
        'phone' => '0500000000',
    ]);

    $this->member = Member::create([
        'user_id' => $this->user->id,
        'member_number' => 'MEM-PRIV1',
        'name' => 'Privacy Member',
        'email' => 'privacy-member@test.com',
        'phone' => '0500000000',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    Account::create([
        'type' => 'fund',
        'name' => 'Fund',
        'balance' => 1500,
        'is_master' => false,
        'member_id' => $this->member->id,
    ]);
    Account::create([
        'type' => 'cash',
        'name' => 'Cash',
        'balance' => 200,
        'is_master' => false,
        'member_id' => $this->member->id,
    ]);
});

test('member data export writes json without mutating ledger', function () {
    $service = app(MemberPrivacyService::class);
    $request = $service->requestExport($this->member);

    expect($request->status)->toBe(MemberPrivacyRequest::STATUS_COMPLETED)
        ->and($request->export_path)->not->toBeEmpty()
        ->and(Storage::disk('local')->exists($request->export_path))->toBeTrue();

    $payload = json_decode(Storage::disk('local')->get($request->export_path), true);
    expect($payload['member']['member_number'])->toBe('MEM-PRIV1')
        ->and((float) $payload['member']['fund_balance'])->toBe(1500.0)
        ->and($this->member->fresh()->name)->toBe('Privacy Member');
});

test('closure approval anonymizes member and user pii', function () {
    $service = app(MemberPrivacyService::class);
    $request = $service->requestClosure($this->member, 'Please delete my personal data');

    $completed = $service->approveClosure($request, $this->admin, 'Approved under PDPL');

    expect($completed->status)->toBe(MemberPrivacyRequest::STATUS_COMPLETED)
        ->and($this->member->fresh()->email)->toContain('@anonymized.local')
        ->and($this->member->fresh()->phone)->toBeNull()
        ->and($this->member->fresh()->status)->toBe('withdrawn')
        ->and($this->user->fresh()->email)->toContain('@anonymized.local')
        ->and($this->user->fresh()->phone)->toBeNull();
});
