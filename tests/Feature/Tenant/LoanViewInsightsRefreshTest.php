<?php

declare(strict_types=1);

use App\Filament\Tenant\Resources\Loans\Widgets\LoanViewInsights;
use App\Models\Tenant\Loan;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Support\Loans\LoanUserFacingStage;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Filament::setCurrentPanel('tenant');

    $this->actingAs(User::create([
        'name' => 'Loan Insights Admin',
        'email' => 'loan-insights-refresh@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]), 'tenant');
});

test('loan view insights reloads fresh loan status for the lifecycle stepper', function () {
    $member = Member::factory()->create(['status' => 'active']);

    $loan = Loan::factory()->for($member)->create([
        'status' => 'pending',
        'amount_requested' => 10000,
    ]);

    $widget = Livewire::test(LoanViewInsights::class, [
        'record' => $loan,
    ])->instance();

    $currentBefore = collect($widget->getData()['steps'] ?? [])
        ->firstWhere('state', 'current')['key'] ?? null;

    expect($currentBefore)->toBe('under_review');

    $loan->update(['status' => 'approved', 'amount_approved' => 10000, 'approved_at' => now()]);

    $currentAfter = collect($widget->getData()['steps'] ?? [])
        ->firstWhere('state', 'current')['key'] ?? null;

    expect($currentAfter)->toBe('approved');
});

test('loan user facing stage stepper reflects approved status after database refresh', function () {
    $member = Member::factory()->create(['status' => 'active']);

    $loan = Loan::factory()->for($member)->create([
        'status' => 'pending',
        'amount_requested' => 5000,
    ]);

    expect(collect(LoanUserFacingStage::stepperFor($loan))
        ->firstWhere('state', 'current')['key'] ?? null)
        ->toBe('under_review');

    $loan->update(['status' => 'approved', 'amount_approved' => 5000, 'approved_at' => now()]);

    expect(collect(LoanUserFacingStage::stepperFor($loan->fresh()))
        ->firstWhere('state', 'current')['key'] ?? null)
        ->toBe('approved');
});
