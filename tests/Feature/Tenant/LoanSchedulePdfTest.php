<?php

declare(strict_types=1);

use App\Filament\Support\MoneyDisplay;
use App\Models\Central\Tenant;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Support\MemberDateDisplay;
use App\Support\MemberLocale;
use App\Support\Pdf\DomPdfFactory;
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
        'name' => 'Schedule PDF Member',
        'email' => 'schedule-pdf@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
    ]);

    $this->member = Member::create([
        'user_id' => $this->memberUser->id,
        'member_number' => 'MEM-PDF01',
        'name' => 'Schedule PDF Member',
        'email' => 'schedule-pdf@fund.test',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($this->member);

    $this->loan = Loan::query()->create([
        'member_id' => $this->member->id,
        'amount' => 3000,
        'amount_requested' => 3000,
        'amount_approved' => 3000,
        'amount_disbursed' => 3000,
        'interest_rate' => 0,
        'term_months' => 3,
        'monthly_repayment' => 1000,
        'total_repaid' => 0,
        'status' => 'active',
        'applied_at' => now()->subMonth(),
        'approved_at' => now()->subMonth(),
        'disbursed_at' => now()->subMonth(),
    ]);

    LoanInstallment::query()->create([
        'loan_id' => $this->loan->id,
        'installment_number' => 1,
        'amount' => 1000,
        'due_date' => now()->addWeek(),
        'status' => 'pending',
    ]);
});

test('member can download active loan schedule pdf', function () {
    $this->actingAs($this->memberUser, 'tenant');

    $response = $this->get('http://'.$this->domain.'/member/loans/'.$this->loan->id.'/schedule/pdf');

    $response->assertSuccessful()
        ->assertHeader('content-type', 'application/pdf');
});

test('loan schedule pdf view uses english labels for english members', function () {
    $this->memberUser->update(['preferred_locale' => 'en']);
    app()->setLocale('en');

    $html = MemberLocale::using($this->memberUser, function (): string {
        return view('pdf.loan-schedule', [
            'loan' => $this->loan->load(['guarantor', 'installments']),
            'member' => $this->member,
            'currency' => 'SAR',
            'brand' => 'Test Fund',
            'accent' => '#534ab7',
            'logoDataUri' => null,
            'outstanding' => 3000.0,
            'installmentsPaid' => 0,
            'installmentsTotal' => 1,
            'moneyHtml' => fn (float $amount): string => MoneyDisplay::pdfHtml($amount, 'SAR')?->toHtml() ?? '—',
            'formatDate' => fn (mixed $date, string $format = 'd M Y'): string => MemberDateDisplay::format($date, $format) ?? '—',
        ])->render();
    });

    expect($html)
        ->toContain('dir="ltr"')
        ->toContain('Loan repayment schedule')
        ->toContain('summary-grid--en')
        ->toContain('currency-code');
});

test('loan schedule pdf view shapes arabic labels for arabic members', function () {
    $this->memberUser->update(['preferred_locale' => 'ar']);
    app()->setLocale('ar');

    $html = MemberLocale::using($this->memberUser, function (): string {
        return view('pdf.loan-schedule', [
            'loan' => $this->loan->load(['guarantor', 'installments']),
            'member' => $this->member,
            'currency' => 'SAR',
            'brand' => 'Test Fund',
            'outstanding' => 3000.0,
            'installmentsPaid' => 0,
            'installmentsTotal' => 1,
            'accent' => '#534ab7',
            'logoDataUri' => null,
            'moneyHtml' => fn (float $amount): string => MoneyDisplay::pdfHtml($amount, 'SAR')?->toHtml() ?? '—',
            'formatDate' => fn (mixed $date, string $format = 'd M Y'): string => MemberDateDisplay::format($date, $format) ?? '—',
        ])->render();
    });

    $shaped = DomPdfFactory::shapeArabicHtml($html);

    expect($html)
        ->toContain('dir="rtl"')
        ->toContain(__('Loan repayment schedule'))
        ->toContain('summary-grid--ar')
        ->toContain('currency-symbol')
        ->toContain(__('Collected'))
        ->toMatch('/<th>'.preg_quote(__('#'), '/').'<\/th>\s*<\/tr>/');

    expect($shaped)
        ->not->toBe($html)
        ->and(preg_match('/[\x{FE70}-\x{FEFF}\x{FB50}-\x{FDFF}]/u', $shaped))->toBe(1);
});

test('arabic member loan schedule pdf download succeeds', function () {
    $this->memberUser->update(['preferred_locale' => 'ar']);

    $this->actingAs($this->memberUser->fresh(), 'tenant');

    $this->get('http://'.$this->domain.'/member/loans/'.$this->loan->id.'/schedule/pdf')
        ->assertSuccessful()
        ->assertHeader('content-type', 'application/pdf');
});

test('member cannot download another members loan schedule pdf', function () {
    $other = Member::create([
        'member_number' => 'MEM-OTHER',
        'name' => 'Other Member',
        'email' => 'other@fund.test',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    $otherLoan = Loan::query()->create([
        'member_id' => $other->id,
        'amount' => 2000,
        'amount_requested' => 2000,
        'amount_approved' => 2000,
        'amount_disbursed' => 2000,
        'interest_rate' => 0,
        'term_months' => 2,
        'monthly_repayment' => 1000,
        'total_repaid' => 0,
        'status' => 'active',
        'applied_at' => now()->subMonth(),
        'approved_at' => now()->subMonth(),
        'disbursed_at' => now()->subMonth(),
    ]);

    $this->actingAs($this->memberUser, 'tenant');

    $this->get('http://'.$this->domain.'/member/loans/'.$otherLoan->id.'/schedule/pdf')
        ->assertForbidden();
});
