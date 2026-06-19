<?php

declare(strict_types=1);

use App\Models\Central\Tenant;
use App\Models\Tenant\Member;
use App\Models\Tenant\MonthlyStatement;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Support\MemberLocale;
use App\Support\Pdf\DomPdfFactory;
use App\Support\StatementSettings;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    $tenant = Tenant::find('testing');
    $this->domain = 'testing.localhost';

    if (! $tenant->domains()->where('domain', $this->domain)->exists()) {
        $tenant->domains()->create(['domain' => $this->domain]);
    }

    $this->memberUser = User::create([
        'name' => 'Statement PDF Member',
        'email' => 'statement-pdf@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
        'preferred_locale' => 'ar',
    ]);

    $this->member = Member::create([
        'user_id' => $this->memberUser->id,
        'member_number' => 'MEM-STMT-PDF',
        'name' => 'Statement PDF Member',
        'email' => 'statement-pdf@fund.test',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($this->member);

    $this->statement = MonthlyStatement::create([
        'member_id' => $this->member->id,
        'period' => '2026-05',
        'opening_balance' => 500,
        'total_contributions' => 100,
        'total_repayments' => 50,
        'closing_balance' => 550,
        'generated_at' => now(),
        'details' => [
            'currency' => 'SAR',
            'cash_closing' => 200,
            'fund_closing' => 350,
            'member_snapshot' => [
                'name' => $this->member->name,
                'member_number' => $this->member->member_number,
            ],
            'period_transactions' => [],
        ],
    ]);
});

test('member can download monthly statement pdf', function () {
    $this->actingAs($this->memberUser, 'tenant');

    $this->get('http://'.$this->domain.'/member/statements/'.$this->statement->id.'/pdf')
        ->assertSuccessful()
        ->assertHeader('content-type', 'application/pdf');
});

test('member cannot download another members statement pdf', function () {
    $other = Member::create([
        'member_number' => 'MEM-OTHER-STMT',
        'name' => 'Other Statement Member',
        'email' => 'other-stmt@fund.test',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    $otherStatement = MonthlyStatement::create([
        'member_id' => $other->id,
        'period' => '2026-04',
        'opening_balance' => 100,
        'total_contributions' => 0,
        'total_repayments' => 0,
        'closing_balance' => 100,
        'generated_at' => now(),
    ]);

    $this->actingAs($this->memberUser, 'tenant');

    $this->get('http://'.$this->domain.'/member/statements/'.$otherStatement->id.'/pdf')
        ->assertForbidden();
});

test('monthly statement pdf view renders arabic labels for arabic members', function () {
    app()->setLocale('ar');

    $html = MemberLocale::using($this->memberUser, function (): string {
        return view('pdf.monthly-statement', [
            'statement' => $this->statement,
            'cfg' => [
                'brand' => StatementSettings::brandName(),
                'tagline' => StatementSettings::tagline(),
                'accent_color' => StatementSettings::accentColor(),
                'footer_disclaimer' => StatementSettings::footerDisclaimer(),
                'signature_line' => StatementSettings::signatureLine(),
                'include_txns' => false,
                'include_loan' => false,
            ],
            'logoDataUri' => null,
        ])->render();
    });

    expect($html)
        ->toContain('dir="rtl"')
        ->toContain('كشف شهري')
        ->toContain('summary-grid--ar')
        ->toContain('currency-symbol');

    $shaped = DomPdfFactory::shapeArabicHtml($html);

    expect(preg_match('/[\x{FE70}-\x{FEFF}\x{FB50}-\x{FDFF}]/u', $shaped))->toBe(1);
});
