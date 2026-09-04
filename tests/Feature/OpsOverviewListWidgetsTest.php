<?php

declare(strict_types=1);

use App\Filament\Tenant\Resources\FundOutRequests\Pages\ListFundOutRequests;
use App\Filament\Tenant\Resources\MemberCashTransferRequests\Pages\ListMemberCashTransferRequests;
use App\Filament\Tenant\Resources\Members\Pages\ListMembers;
use App\Filament\Tenant\Widgets\FundOutRequestInsightsWidget;
use App\Filament\Tenant\Widgets\MemberCashTransferRequestInsightsWidget;
use App\Filament\Tenant\Widgets\MemberInsightsWidget;
use App\Models\Tenant\FundOutRequest;
use App\Models\Tenant\Member;
use App\Models\Tenant\MemberCashTransferRequest;
use App\Models\Tenant\Setting;
use App\Models\Tenant\User;
use App\Support\CollectionInsightsCache;
use App\Support\TenantRuntimeCache;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Filament::setCurrentPanel('tenant');

    $this->admin = User::create([
        'name' => 'Ops Overview Admin',
        'email' => 'ops-overview-admin@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);
});

test('members list mounts member insights header widget', function () {
    Livewire::actingAs($this->admin, 'tenant')
        ->test(ListMembers::class)
        ->assertSuccessful()
        ->assertSeeLivewire(MemberInsightsWidget::class);
});

test('member insights overview uses ops overview chrome', function () {
    Member::factory()->create([
        'status' => 'active',
        'monthly_contribution_amount' => 500,
    ]);
    CollectionInsightsCache::bump(CollectionInsightsCache::DOMAIN_MEMBERS);

    $html = Livewire::actingAs($this->admin, 'tenant')
        ->test(MemberInsightsWidget::class)
        ->assertSuccessful()
        ->html();

    expect($html)->toContain('ff-ops-overview')
        ->toContain(__('Overview'));
});

test('member insights overview money cells use arabic riyal svg when currency is sar', function () {
    Setting::set('general', 'currency', 'SAR');
    Member::factory()->create([
        'status' => 'active',
        'monthly_contribution_amount' => 1250,
    ]);
    CollectionInsightsCache::bump(CollectionInsightsCache::DOMAIN_MEMBERS);

    app()->setLocale('ar');
    session()->put('locale', 'ar');

    $html = Livewire::actingAs($this->admin, 'tenant')
        ->test(MemberInsightsWidget::class)
        ->assertSuccessful()
        ->html();

    expect($html)->toContain('ff-ops-overview')
        ->toContain('ff-sar-symbol__img');
});

test('fund outs list mounts ops overview widget with money markup', function () {
    Setting::set('general', 'currency', 'SAR');
    TenantRuntimeCache::forget('fund_out_request_insights.v1');

    $member = Member::factory()->create();
    FundOutRequest::create([
        'member_id' => $member->id,
        'amount' => 1250,
        'status' => 'pending',
    ]);

    Livewire::actingAs($this->admin, 'tenant')
        ->test(ListFundOutRequests::class)
        ->assertSuccessful()
        ->assertSeeLivewire(FundOutRequestInsightsWidget::class);

    $html = Livewire::actingAs($this->admin, 'tenant')
        ->test(FundOutRequestInsightsWidget::class)
        ->assertSuccessful()
        ->html();

    expect($html)->toContain('ff-ops-overview')
        ->toContain('ff-sar-symbol__img')
        ->toContain(__('Overview'));
});

test('cash transfers list mounts ops overview widget with money markup', function () {
    Setting::set('general', 'currency', 'SAR');
    TenantRuntimeCache::forget('member_cash_transfer_request_insights.v1');

    $from = Member::factory()->create();
    $to = Member::factory()->create();
    MemberCashTransferRequest::create([
        'from_member_id' => $from->id,
        'to_member_id' => $to->id,
        'recipient_name' => $to->name,
        'amount' => 800,
        'status' => 'pending',
    ]);

    Livewire::actingAs($this->admin, 'tenant')
        ->test(ListMemberCashTransferRequests::class)
        ->assertSuccessful()
        ->assertSeeLivewire(MemberCashTransferRequestInsightsWidget::class);

    $html = Livewire::actingAs($this->admin, 'tenant')
        ->test(MemberCashTransferRequestInsightsWidget::class)
        ->assertSuccessful()
        ->html();

    expect($html)->toContain('ff-ops-overview')
        ->toContain('ff-sar-symbol__img')
        ->toContain(__('Overview'));
});
