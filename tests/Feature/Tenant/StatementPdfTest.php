<?php

declare(strict_types=1);

use App\Models\Central\Tenant;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\LoanRepayment;
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

test('monthly statement blade markup is not corrupted', function () {
    $blade = file_get_contents(resource_path('views/pdf/monthly-statement.blade.php'));

    expect($blade)
        ->toContain('<thead>')
        ->toContain('section-title')
        ->not->toMatch('/<the\s+ad>/')
        ->not->toMatch('/secti\s+on/')
        ->not->toMatch('/class\s{2,}=/');
});

test('member can download monthly statement pdf', function () {
    $statement = app(MonthlyStatementService::class)->generateForMember($this->member, '2026-05');

    $this->actingAs($this->memberUser, 'tenant');

    $this->get('http://'.$this->domain.'/member/statements/'.$statement->id.'/pdf')
        ->assertSuccessful()
        ->assertHeader('content-type', 'application/pdf');
});

test('arabic statement pdf download uses configured amiri font family', function () {
    $this->memberUser->update(['preferred_locale' => 'ar']);
    Setting::set(StatementSettings::GROUP, 'font_ar', StatementSettings::FONT_AMIRI);

    $statement = app(MonthlyStatementService::class)->generateForMember($this->member, '2026-05');

    $this->actingAs($this->memberUser->fresh(), 'tenant');

    $response = $this->get('http://'.$this->domain.'/member/statements/'.$statement->id.'/pdf');

    $response->assertSuccessful()
        ->assertHeader('content-type', 'application/pdf');

    $pdf = $response->getContent();

    expect($pdf)
        ->toContain('Amiri')
        ->not->toContain('????');
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

    // Lifetime repayments come from LoanRepayment rows (same source as the member portal), not installments.
    LoanRepayment::query()->create([
        'loan_id' => $loan->id,
        'amount' => 1000,
        'paid_at' => '2026-04-02',
    ]);
    LoanRepayment::query()->create([
        'loan_id' => $loan->id,
        'amount' => 1000,
        'paid_at' => '2026-05-03',
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
        ->and($details['lifetime']['total_contributions'])->toEqual(1000.0)
        ->and($details['lifetime']['total_repayments'])->toEqual(2000.0)
        ->and($details['lifetime']['collection_total'])->toEqual(3000.0)
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
        ->toContain('صندوق السمان العائلي')
        ->toContain('stmt-page-footer')
        ->toContain('stmt-font-warmup')
        ->toContain('font-family: Amiri')
        ->toContain('position: fixed')
        ->toContain('أحمد المحمود')
        ->toContain('Lifetime summary')
        ->toContain('Loan repayments')
        ->toContain('Collection')
        ->toContain('Contributions')
        ->toContain('Year-by-year summary')
        ->not->toContain('Lifetime contributions')
        ->not->toContain('Loan Repayments Total')
        ->not->toContain('Collection Total')
        ->not->toContain('Total lifetime contributions')
        ->toContain('Total')
        ->toContain('Cash balance')
        ->toContain('Fund balance')
        ->toContain('SA0380000000608010167519');

    $shaped = DomPdfFactory::shapeArabicHtml($html);

    expect(preg_match('/[\x{FE70}-\x{FEFF}\x{FB50}-\x{FDFF}]/u', $shaped))->toBe(1);
});

test('year-by-year summary includes total cash and fund balance columns', function () {
    $statement = app(MonthlyStatementService::class)->generateForMember($this->member, '2026-05');
    $details = $statement->details ?? [];
    $details['yearly_history'] = [
        ['year' => 2026, 'contributions' => 1000.0, 'repayments' => 250.0, 'cash_balance' => 120.0, 'fund_balance' => 900.0],
        ['year' => 2025, 'contributions' => 100.0, 'repayments' => 400.0, 'cash_balance' => 50.0, 'fund_balance' => 700.0],
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
        ->toContain('>Cash balance<')
        ->toContain('>Fund balance<')
        ->not->toContain('>Net<')
        ->toContain('1,250.00')
        ->toContain('120.00')
        ->toContain('900.00');
});

test('activity table uses rolling six-month window with date column', function () {
    $statement = app(MonthlyStatementService::class)->generateForMember($this->member, '2026-05');
    $details = $statement->details ?? [];

    expect($details['current_year_months'])->toHaveCount(6)
        ->and($details['current_year_totals']['month_count'])->toBe(6)
        ->and($details['current_year_totals']['from_period'])->toBe('2025-12')
        ->and($details['current_year_totals']['to_period'])->toBe('2026-05')
        ->and($details['yearly_history'][0])->toHaveKeys(['cash_balance', 'fund_balance']);

    $details['current_year_months'] = [
        [
            'month' => 5,
            'year' => 2026,
            'period' => '2026-05',
            'contributions' => 1000,
            'repayments' => 250,
            'contribution_dates' => ['2026-05-10'],
            'repayment_dates' => ['2026-05-03'],
        ],
    ];
    $details['current_year_totals'] = [
        'year' => 2026,
        'month_count' => 6,
        'from_period' => '2025-12',
        'to_period' => '2026-05',
        'from_year' => 2025,
        'from_month' => 12,
        'to_year' => 2026,
        'to_month' => 5,
        'contributions' => 1000,
        'repayments' => 250,
    ];
    $details['fund_closing'] = 1500.25;
    $details['cash_closing'] = 420.5;
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
                'include_loan' => true,
            ],
            'logoDataUri' => null,
        ])->render();
    });

    expect($html)
        ->toContain('6-Month Activity')
        ->toContain('Dec-2025')
        ->toContain('May-2026')
        ->toContain(' to ')
        ->toContain('stmt-tfoot-pill')
        ->toContain('stmt-kpi-pill')
        ->toContain('stmt-balance-pill--success')
        ->toContain('Fund at period end')
        ->toContain('Cash at period end')
        ->toContain('Monthly contribution')
        ->toContain('.stmt-balance-pill')
        ->toContain('text-align: center')
        ->toContain('section-title__meta')
        ->toContain('Since membership year')
        ->toContain('Summary as of')
        ->not->toContain('[Summary as of')
        ->toContain('table-header-group')
        ->toContain('stmt-section--keep')
        ->toContain('page-break-after: avoid')
        ->toContain('>Date<')
        ->toContain('2026-05-10')
        ->toContain('6-Month contributions')
        ->toContain('6-Month repayments')
        ->toContain('stmt-progress')
        ->toContain('stmt-progress__cell')
        ->toContain('stmt-mini-track')
        ->toContain('stmt-mini-pct');

    // Green/red pills force a white SAR glyph when the Arabic SVG symbol is used.
    if (preg_match('/stmt-balance-pill--success[^>]*>[\s\S]*?currency-symbol/', $html) === 1) {
        expect($html)->toContain(App\Support\Pdf\PdfAssets::sarSymbolDataUri('#ffffff'));
    }
    $details['fund_closing'] = -75.5;
    $statement->details = $details;
    $negativeHtml = MemberLocale::usingPreferred($this->memberUser, function () use ($statement): string {
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

    expect($negativeHtml)->toContain('stmt-balance-pill--danger');

    // Arabic SAR uses an SVG img — assert the white glyph data-uri when present in the danger pill.
    if (preg_match('/stmt-balance-pill--danger[^>]*>[\s\S]*?currency-symbol/', $negativeHtml) === 1) {
        expect($negativeHtml)->toContain(PdfAssets::sarSymbolDataUri('#ffffff'));
    }
});

test('period transactions table includes account column for debit and credit', function () {
    $statement = app(MonthlyStatementService::class)->generateForMember($this->member, '2026-05');
    $details = $statement->details ?? [];
    $details['period_transactions'] = [
        [
            'date' => '2026-05-10 12:00:00',
            'description' => 'Member deposit',
            'type' => 'credit',
            'amount' => 500,
            'account_type' => 'cash',
        ],
        [
            'date' => '2026-05-12 09:00:00',
            'description' => 'Contribution transfer',
            'type' => 'debit',
            'amount' => 250,
            'account_type' => 'fund',
        ],
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
                'include_txns' => true,
                'include_loan' => false,
            ],
            'logoDataUri' => null,
        ])->render();
    });

    expect($html)
        ->toContain('Period transactions')
        ->toContain('>Account<')
        ->toContain('Cash')
        ->toContain('Fund')
        ->toContain('txn-type--credit')
        ->toContain('txn-type--debit')
        ->toContain('amount--success')
        ->toContain('amount--danger')
        ->toContain('Credit')
        ->toContain('Debit');
});

test('monthly statement pdf renders with loans and activity without dompdf table errors', function () {
    $statement = app(MonthlyStatementService::class)->generateForMember($this->member, '2026-05');
    $details = $statement->details ?? [];
    $details['loans'] = [
        [
            'id' => 99,
            'status' => 'active',
            'amount_approved' => 12000,
            'amount_disbursed' => 12000,
            'emi_amount' => 1000,
            'disbursed_at' => '2026-01-15',
            'repay_percent' => 33,
            'installments_total' => 12,
            'installments_paid' => 4,
            'outstanding' => 8000,
        ],
    ];
    $statement->details = $details;

    $pdf = DomPdfFactory::loadView('pdf.monthly-statement', [
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
        'pdfFont' => StatementSettings::pdfFontFamily('en'),
    ]);

    expect($pdf->output())->not->toBeEmpty();
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
            'year' => 2026,
            'period' => '2026-05',
            'contributions' => 1000,
            'repayments' => 250,
            'contribution_dates' => ['2026-05-10'],
            'repayment_dates' => ['2026-05-03'],
        ],
    ];
    $details['current_year_totals'] = [
        'year' => 2026,
        'month_count' => 6,
        'from_period' => '2025-12',
        'to_period' => '2026-05',
        'from_year' => 2025,
        'from_month' => 12,
        'to_year' => 2026,
        'to_month' => 5,
        'contributions' => 1000,
        'repayments' => 250,
        'max_activity' => 1000,
    ];
    $details['yearly_history'] = [
        ['year' => 2026, 'contributions' => 1000, 'repayments' => 250, 'cash_balance' => 100, 'fund_balance' => 800],
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
        ->toContain('font-size: 13.5px')
        ->toContain('font-size: 18px')
        ->toContain('font-size: 14px')
        ->toContain('stmt-hero__meta-label')
        ->toContain('font-weight: 700')
        ->toContain('stmt-hero__copy')
        ->toContain('stmt-hero__meta')
        ->toContain('stmt-hero__meta-label')
        ->toContain('stmt-meta--ar')
        ->toContain('margin-left: auto')
        ->toContain('width="12"')
        ->toContain('الفترة:')
        ->toContain('اعتباراً من:')
        ->toContain('كشف حساب شهري')
        ->toContain('صندوق السمان العائلي')
        ->toContain('ملخص مدى الحياة')
        ->toContain('الملخص حتى تاريخ')
        ->toContain('سداد القروض')
        ->toContain('التحصيل')
        ->toContain('المساهمات')
        ->not->toContain('المساهمات مدى الحياة')
        ->not->toContain('إجمالي سداد القروض')
        ->not->toContain('إجمالي التحصيل')
        ->toContain('ملخص حسب السنة')
        ->toContain('منذ سنة الانتساب')
        ->not->toContain('[الملخص حتى تاريخ');

    expect(strpos($html, '<span dir="ltr">'))->toBeGreaterThan(0);
    $lifetimeTitlePos = strpos($html, 'ملخص مدى الحياة');
    $summaryAsOfPos = strpos($html, 'الملخص حتى تاريخ');
    expect($lifetimeTitlePos)->toBeGreaterThan(0)
        ->and($summaryAsOfPos)->toBeGreaterThan(0)
        ->and($summaryAsOfPos)->toBeLessThan($lifetimeTitlePos);
    expect(preg_match('/<span dir="ltr">\d{4}-\d{2}-\d{2}<\/span> : الملخص حتى تاريخ/', $html))->toBe(1);
    expect(strpos($html, 'section-title__meta'))->toBeLessThan(strpos($html, 'ملخص حسب السنة'));
    expect(strpos($html, 'منذ سنة الانتساب'))->toBeLessThan(strpos($html, 'ملخص حسب السنة'));
    expect($html)->not->toContain('[[ASOF]]')
        ->toContain('data-table')
        ->toContain('اعتباراً من')
        ->toContain('رقم العضو')
        ->toContain('نشاط 6 أشهر')
        ->toContain('السنة')
        ->toContain('إلى')
        ->toContain('مساهمات 6 أشهر')
        ->toContain('سداد 6 أشهر')
        ->not->toContain('stmt-bar-track')
        ->not->toContain('stmt-inline-totals')
        ->not->toContain('stmt-year-chart');

    $heroCopyPos = strpos($html, 'stmt-hero__copy');
    expect($heroCopyPos)->toBeGreaterThan(0);
    expect(strpos($html, 'stmt-hero__meta-value', $heroCopyPos))
        ->toBeLessThan(strpos($html, 'الفترة:', $heroCopyPos));
    expect(strpos($html, '<span dir="ltr">2026</span>', $heroCopyPos))
        ->toBeLessThan(strpos($html, 'الفترة:', $heroCopyPos));
    expect(strpos($html, 'نشاط 6 أشهر'))->toBeGreaterThan(0);

    expect(strpos($html, 'رصيد الصندوق في نهاية الفترة'))->toBeLessThan(strpos($html, 'رصيد الصندوق في بداية الفترة'));
    expect(strpos($html, 'class="stmt-meta__value"'))->toBeLessThan(strpos($html, 'class="stmt-meta__label"'));

    $shaped = DomPdfFactory::shapeArabicHtml($html);

    expect(preg_match('/[\x{FE70}-\x{FEFF}\x{FB50}-\x{FDFF}]/u', $shaped))->toBe(1);
});
