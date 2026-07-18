<?php

declare(strict_types=1);

use App\Filament\Member\Resources\MyContributions\Pages\ListMyContributions;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Support\BusinessDaySettings;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-06-15 10:00:00'));
    $this->initializeTenancy();
    BusinessDaySettings::saveFromForm('2026-06-15');

    $this->memberUser = User::create([
        'name' => 'KPI Modal Member',
        'email' => 'kpi-modal-member@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
    ]);

    $this->member = Member::create([
        'user_id' => $this->memberUser->id,
        'member_number' => 'KPI-001',
        'name' => 'KPI Modal Member',
        'email' => 'kpi-modal-member@fund.test',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($this->member);
    Filament::setCurrentPanel('member');
});

afterEach(function (): void {
    Carbon::setTestNow();
    BusinessDaySettings::saveFromForm(null);
});

test('dismissing request larger cycle amount modal keeps contribution KPI strip', function () {
    Livewire::actingAs($this->memberUser, 'tenant')
        ->test(ListMyContributions::class)
        ->assertSuccessful()
        ->assertSee(__('My Contributions'), false)
        ->assertActionVisible('requestOpenCycleAmount')
        ->assertActionVisible('applyOpenPeriodContribution')
        ->assertSee('ff-member-contributions-stats', false)
        ->assertSee(__('Total contributed'), false)
        ->assertSee(__('Cash gap'), false)
        ->mountAction('requestOpenCycleAmount')
        ->assertActionMounted('requestOpenCycleAmount')
        ->unmountAction()
        ->assertSuccessful()
        ->assertSee(__('My Contributions'), false)
        ->assertActionVisible('requestOpenCycleAmount')
        ->assertActionVisible('applyOpenPeriodContribution')
        ->assertSee('ff-member-contributions-stats', false)
        ->assertSee(__('Total contributed'), false)
        ->assertSee(__('Cash gap'), false)
        ->assertDontSee(__('Loading contribution summary…'), false);
});
