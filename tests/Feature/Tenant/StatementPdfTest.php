<?php

declare(strict_types=1);

use App\Models\Central\Tenant;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\LoanTier;
use App\Models\Tenant\Member;
use App\Models\Tenant\MembershipApplication;
use App\Models\Tenant\MonthlyStatement;
use App\Models\Tenant\Setting;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\MonthlyStatementService;
use App\Support\MemberLocale;
use App\Support\Pdf\DomPdfFactory;
use App\Support\PublicPageSettings;
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
        'name' => 'أحمد المحمود',
        'email' => 'statement-pdf@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
        'preferred_locale' => 'en',
    ]);

    $this->member = Member::create([
        'user_id' => $this->memberUser->id,
        'member_number' => 'MEM-STMT-PDF',
        'name' => 'أحمد المحمود',
        'email' => 'statement-pdf@fund.test',
        'phone' => '0500000000',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYears(2)->startOfYear(),
        'status' => 'active',
    ]);

    MembershipApplication::query()->create([
        'member_id' => $this->member->id,
        'name' => $this->member->name,
        'email' => $this->member->email,
        'phone' => '0500000000',
        'mobile_phone' => '0500000000',
        'home_phone' => '0110000000',
        'work_phone' => '0120000000',
        'iban' => 'SA0380000000608010167519',
        'bank_account_number' => '608010167519',
        'status' => 'approved',
        'application_type' => 'new',
        'membership_fee_amount' => 500,
        'membership_date' => $this->member->joined_at,
        'reviewed_at' => now()->subYears(2),
    ]);

    app(AccountingService::class)->createMemberAccounts($this->member);

    Setting::set(PublicPageSettings::GROUP, 'fund_name_en', 'Samman Family Fund');
    Setting::set(PublicPageSettings::GROUP, 'fund_name_ar', 'صندوق السمان العائلي');
});

test('member can download monthly statement pdf', function () {
    $statement = app(MonthlyStatementService::class)->generateForMember($this->member, '2026-05');

    $this->actingAs($this->memberUser, 'tenant');

    $this->get('http://' . $this->domain . '/member/statements/' . $statement->id . '/pdf')
        ->assertSuccessful()
        ->assertHeader('content-type', 'application/pdf');
});

test('arabic statement pdf download uses configured amiri font family', function () {
    $this->memberUser->update(['preferred_locale' => 'ar']);
    Setting::set(StatementSettings::GROUP, 'font_ar', StatementSettings::FONT_AMIRI);

    $statement = app(MonthlyStatementService::class)->generateForMember($this->member, '2026-05');

    $this->actingAs($this->memberUser->fresh(), 'tenant');

    $response = $this->get('http://' . $this->domain . '/member/statements/' . $statement->id . '/pdf');

    $response->assertSuccessful()
        ->assertHeader('content-type', 'application/pdf');

    expect($response->getContent())->toContain('Amiri');
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

test('monthly statement details include fund names profile loans and yearly history', function () {
    Contribution::query()->create([
        'member_id' => $this->member->id,
        'period' => Contribution::periodDate(5, 2026),
        'amount' => 1000,
        'status' => 'posted',
        'paid_at' => '2026-05-10',
    ]);

    $tier = LoanTier::query()->create([
        'tier_number' => 99,
        'label' => 'Statement Tier',
        'min_amount' => 1000,
        'max_amount' => 50000,
        'min_monthly_installment' => 500,
        'is_active' => true,
    ]);

    $loan = Loan::query()->create([
        'member_id' => $this->member->id,
        'loan_tier_id' => $tier->id,
        'amount' => 10000,
        'amount_requested' => 10000,
        'amount_approved' => 10000,
        'amount_disbursed' => 10000,
        'interest_rate' => 0,
        'term_months' => 10,
        'monthly_repayment' => 1000,
        'total_repaid' => 2000,
        'status' => 'active',
        'applied_at' => now()->subMonths(4),
        'approved_at' => now()->subMonths(3),
        'disbursed_at' => now()->subMonths(3),
    ]);

    LoanInstallment::query()->create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 1000,
        'due_date' => '2026-04-01',
        'paid_at' => '2026-04-02',
        'status' => 'paid',
    ]);
    LoanInstallment::query()->create([
        'loan_id' => $loan->id,
        'installment_number' => 2,
        'amount' => 1000,
        'due_date' => '2026-05-01',
        'paid_at' => '2026-05-03',
        'status' => 'paid',
    ]);
    LoanInstallment::query()->create([
        'loan_id' => $loan->id,
        'installment_number' => 3,
        'amount' => 1000,
        'due_date' => '2026-06-01',
        'status' => 'pending',
    ]);

    $statement = app(MonthlyStatementService::class)->generateForMember($this->member, '2026-05');
    $details = $statement->details;

    expect($details['fund_name_en'])->toBe('Samman Family Fund')
        ->and($details['fund_name_ar'])->toBe('صندوق السمان العائلي')
        ->and($details['member_snapshot']['iban'])->toBe('SA0380000000608010167519')
        ->and($details['member_snapshot']['home_phone'])->toBe('0110000000')
        ->and($details['member_snapshot']['mobile_phone'])->toBe('0500000000')
        ->and($details['loans'])->toHaveCount(1)
        ->and($details['loans'][0]['emi_amount'])->toEqual(1000)
        ->and($details['yearly_history'])->not->toBeEmpty()
        ->and($details['current_year_months'])->not->toBeEmpty()
        ->and($details['lifetime']['loan_count'])->toBe(1)
        ->and($details['fees']['total'])->toBeGreaterThanOrEqual(500);
});

test('monthly statement pdf prefers member locale and shapes arabic names in english pdfs', function () {
    $statement = app(MonthlyStatementService::class)->generateForMember($this->member, '2026-05');

    app()->setLocale('ar');

    $html = MemberLocale::usingPreferred($this->memberUser, function () use ($statement): string {
        return view('pdf.monthly-statement', [
            'statement' => $statement,
            'cfg' => [
                'brand' => 'Samman Family Fund',
                'fund_name' => 'Samman Family Fund',
                'tagline' => StatementSettings::tagline(),
                'accent_color' => StatementSettings::accentColor(),
                'footer_disclaimer' => StatementSettings::footerDisclaimer(),
                'signature_line' => StatementSettings::signatureLine(),
                'include_txns' => false,
                'include_loan' => true,
            ],
            'logoDataUri' => null,
        ])->render();
    });

    expect($html)
        ->toContain('dir="ltr"')
        ->toContain('Samman Family Fund')
        ->toContain('أحمد المحمود')
        ->toContain('Lifetime summary')
        ->toContain('Year-by-year summary')
        ->toContain('Total')
        ->toContain('Net')
        ->toContain('SA0380000000608010167519');

    $shaped = DomPdfFactory::shapeArabicHtml($html);

    expect(preg_match('/[\x{FE70}-\x{FEFF}\x{FB50}-\x{FDFF}]/u', $shaped))->toBe(1);
});

test('year-by-year summary includes total and signed net columns', function () {
    $statement = app(MonthlyStatementService::class)->generateForMember($this->member, '2026-05');
    $details = $statement->details ?? [];
    $details['yearly_history'] = [
        ['year' => 2026, 'contributions' => 1000.0, 'repayments' => 250.0],
        ['year' => 2025, 'contributions' => 100.0, 'repayments' => 400.0],
    ];
    $statement->details = $details;

    $html = MemberLocale::usingPreferred($this->memberUser, function () use ($statement): string {
        return view('pdf.monthly-statement', [
            'statement' => $statement,
            'cfg' => [
                'brand' => 'Samman Family Fund',
                'fund_name' => 'Samman Family Fund',
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
        ->toContain('>Total<')
        ->toContain('>Net<')
        ->toContain('+750.00')
        ->toContain('−300.00')
        ->toContain('1,250.00');
});

test('monthly statement pdf view renders arabic labels for arabic members', function () {
    $this->memberUser->update(['preferred_locale' => 'ar']);
    Setting::set(StatementSettings::GROUP, 'font_ar', StatementSettings::FONT_AMIRI);
    $statement = app(MonthlyStatementService::class)->generateForMember($this->member, '2026-05');
    $details = $statement->details ?? [];
    $details['currency'] = 'SAR';
    $details['current_year_months'] = [
        [
            'month' => 5,
            'contributions' => 1000,
            'repayments' => 250,
            'contribution_dates' => ['2026-05-10'],
            'repayment_dates' => ['2026-05-03'],
        ],
    ];
    $details['current_year_totals'] = [
        'year' => 2026,
        'contributions' => 1000,
        'repayments' => 250,
        'max_activity' => 1000,
    ];
    $details['yearly_history'] = [
        ['year' => 2026, 'contributions' => 1000, 'repayments' => 250],
    ];
    $statement->details = $details;

    $html = MemberLocale::usingPreferred($this->memberUser, function () use ($statement): string {
        return view('pdf.monthly-statement', [
            'statement' => $statement,
            'cfg' => [
                'brand' => 'صندوق السمان العائلي',
                'fund_name' => 'صندوق السمان العائلي',
                'tagline' => StatementSettings::tagline(),
                'accent_color' => StatementSettings::accentColor(),
                'footer_disclaimer' => StatementSettings::footerDisclaimer(),
                'signature_line' => StatementSettings::signatureLine(),
                'include_txns' => false,
                'include_loan' => false,
            ],
            'logoDataUri' => null,
            'pdfFont' => StatementSettings::pdfFontFamily('ar'),
        ])->render();
    });

    expect($html)
        ->toContain('dir="rtl"')
        ->toContain('direction: rtl')
        ->toContain('text-align: right')
        ->toContain('font-family: Amiri')
        ->toContain('stmt-hero__copy')
        ->toContain('stmt-bar-track--end')
        ->toContain('width="12"')
        ->toContain('<span dir="ltr">2026</span> — ')
        ->toContain(' : الفترة')
        ->toContain('كشف حساب شهري')
        ->toContain('صندوق السمان العائلي')
        ->toContain('ملخص مدى الحياة');

    expect(strpos($html, '<span dir="ltr">2026</span> — '))->toBeLessThan(strpos($html, 'نشاط السنة الحالية'));
    expect(strpos($html, '<span dir="ltr">2026</span>'))->toBeLessThan(strpos($html, ' : الفترة'));

    expect(strpos($html, 'رصيد الإغلاق'))->toBeLessThan(strpos($html, 'الرصيد الافتتاحي'));
    expect(strpos($html, 'class="stmt-bar-amount"'))->toBeLessThan(strpos($html, 'class="stmt-bar-caption"'));

    $shaped = DomPdfFactory::shapeArabicHtml($html);

    expect(preg_match('/[\x{FE70}-\x{FEFF}\x{FB50}-\x{FDFF}]/u', $shaped))->toBe(1);
});
