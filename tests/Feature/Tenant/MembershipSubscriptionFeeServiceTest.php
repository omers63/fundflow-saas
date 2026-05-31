<?php

use App\Models\Tenant\Account;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\MembershipApplication;
use App\Services\AccountingService;
use App\Services\ContributionCycleService;
use App\Services\FundPostingService;
use App\Services\MembershipApplicationApprovalService;
use App\Services\MembershipSubscriptionFeeService;
use App\Support\PublicPageSettings;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Storage::fake('public');

    Account::query()->delete();
    MembershipApplication::query()->delete();
    BankTransaction::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fees', 'name' => 'Master Fees', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'bank', 'name' => 'Master Bank', 'balance' => 0, 'is_master' => true]);

    $this->subscriptionFees = app(MembershipSubscriptionFeeService::class);
    $this->approval = app(MembershipApplicationApprovalService::class);
    $this->accounting = app(AccountingService::class);
});

function makePendingFeeApplication(array $overrides = []): MembershipApplication
{
    return MembershipApplication::create(array_merge([
        'name' => 'Jane Applicant',
        'email' => 'jane-fee@example.com',
        'password' => 'SecurePass1!',
        'phone' => '+966501234567',
        'application_type' => 'new',
        'national_id' => '1234567890',
        'date_of_birth' => '1990-01-15',
        'address' => '123 Main Street',
        'city' => 'Riyadh',
        'mobile_phone' => '+966501234567',
        'bank_account_number' => '1234567890123456',
        'iban' => 'SA0380000000608010167519',
        'membership_fee_amount' => 75,
        'membership_fee_required_amount' => 50,
        'membership_fee_transfer_date' => now()->toDateString(),
        'membership_fee_transfer_reference' => 'TXN-FEE-001',
        'membership_fee_receipt_path' => 'applications/receipts/test-receipt.jpg',
        'status' => 'pending',
    ], $overrides));
}

test('approval is blocked when transfer amount is below required subscription fee', function () {
    $application = makePendingFeeApplication([
        'membership_fee_amount' => 40,
        'membership_fee_required_amount' => 50,
    ]);

    expect(fn () => $this->approval->approve($application))
        ->toThrow(InvalidArgumentException::class);
});

test('approving application without transfer receipt still posts uncleared subscription fee accounting', function () {
    $application = makePendingFeeApplication([
        'membership_fee_receipt_path' => null,
    ]);

    $member = $this->approval->approve($application);

    expect(BankTransaction::query()
        ->where('membership_application_id', $application->fresh()->id)
        ->where('is_cleared', false)
        ->exists())->toBeTrue()
        ->and((float) $member->cashAccount->fresh()->balance)->toBe(25.0);
});

test('approving application posts subscription fee accounting and leaves excess in member cash', function () {
    $application = makePendingFeeApplication([
        'membership_fee_transfer_date' => '2026-02-15',
    ]);

    $member = $this->approval->approve($application);

    $masterCash = Account::masterCash();
    $masterFees = Account::masterFees();
    $memberCash = $member->cashAccount;

    expect((float) $masterCash->fresh()->balance)->toBe(75.0)
        ->and((float) $masterFees->fresh()->balance)->toBe(50.0)
        ->and((float) $memberCash->fresh()->balance)->toBe(25.0);

    $bankTxn = BankTransaction::query()
        ->where('membership_application_id', $application->fresh()->id)
        ->first();

    expect($bankTxn)->not->toBeNull()
        ->and($bankTxn->is_cleared)->toBeFalse()
        ->and((float) $bankTxn->amount)->toBe(75.0)
        ->and($bankTxn->master_cash_transaction_id)->not->toBeNull()
        ->and($bankTxn->transaction_date->toDateString())->toBe('2026-02-15');

    expect(Carbon::parse($memberCash->transactions()->where('type', 'credit')->value('transacted_at'))->toDateString())
        ->toBe('2026-02-15')
        ->and(Carbon::parse($memberCash->transactions()->where('type', 'debit')->value('transacted_at'))->toDateString())
        ->toBe('2026-02-15')
        ->and(Carbon::parse($masterCash->transactions()->where('type', 'credit')->value('transacted_at'))->toDateString())
        ->toBe('2026-02-15');
});

test('csv import subscription fee does not trigger contribution collection on approval', function () {
    PublicPageSettings::save([
        ...PublicPageSettings::defaults(),
        'fee_new' => '50',
    ]);

    [$currentMonth, $currentYear] = app(ContributionCycleService::class)->currentOpenPeriod();

    $application = makePendingFeeApplication([
        'membership_fee_amount' => 60,
        'membership_fee_required_amount' => 50,
        'membership_fee_transfer_reference' => 'CSV-TXN-NOCOLLECT',
        'import_arrears_cutoff_date' => now()->subMonths(2)->toDateString(),
        'import_cutoff_cash_balance' => 0,
        'import_cutoff_fund_balance' => 0,
    ]);

    $member = $this->approval->approve($application);

    expect($member->contribution_arrears_cutoff_date)->not->toBeNull()
        ->and((float) $member->cashAccount->fresh()->balance)->toBe(10.0)
        ->and(
            Contribution::query()
                ->where('member_id', $member->id)
                ->forPeriod($currentMonth, $currentYear)
                ->exists(),
        )->toBeFalse();
});

test('imported transfer amount triggers subscription fee posting on approval', function () {
    PublicPageSettings::save([
        ...PublicPageSettings::defaults(),
        'fee_new' => '50',
    ]);

    $application = makePendingFeeApplication([
        'membership_fee_amount' => 60,
        'membership_fee_required_amount' => null,
        'membership_fee_transfer_reference' => 'CSV-TXN-99',
    ]);

    expect(app(MembershipSubscriptionFeeService::class)->applicationRequiresSubscriptionFee($application))->toBeTrue();

    $member = $this->approval->approve($application);

    expect((float) $member->cashAccount->fresh()->balance)->toBe(10.0);
});

test('bank statement match clears uncleared subscription fee transaction', function () {
    $application = makePendingFeeApplication();
    $member = $this->approval->approve($application);

    $uncleared = BankTransaction::query()
        ->where('membership_application_id', $application->fresh()->id)
        ->firstOrFail();

    $imported = BankTransaction::create([
        'bank_statement_id' => $uncleared->bank_statement_id,
        'transaction_date' => $uncleared->transaction_date,
        'description' => 'Bank import match',
        'amount' => $uncleared->amount,
        'reference' => $uncleared->reference,
        'status' => 'imported',
        'member_id' => $member->id,
        'hash' => md5('imported-subscription-fee'),
        'is_cleared' => false,
    ]);

    app(FundPostingService::class)->clearTransaction($uncleared, $imported);

    expect($uncleared->fresh()->is_cleared)->toBeTrue()
        ->and($imported->fresh()->is_cleared)->toBeTrue()
        ->and($imported->fresh()->membership_application_id)->toBe($application->fresh()->id);
});

test('enrollment dependent applications skip subscription fee posting', function () {
    $parent = makePendingFeeApplication(['email' => 'parent@example.com', 'name' => 'Parent']);
    $parentMember = $this->approval->approve($parent);

    $dependent = makePendingFeeApplication([
        'email' => 'child@example.com',
        'name' => 'Child',
        'parent_application_id' => $parent->fresh()->id,
        'membership_fee_amount' => null,
        'membership_fee_required_amount' => null,
        'membership_fee_receipt_path' => null,
        'membership_fee_transfer_reference' => null,
    ]);

    $childMember = $this->approval->approve($dependent);

    expect(BankTransaction::query()->where('membership_application_id', $dependent->id)->exists())->toBeFalse()
        ->and((float) $childMember->cashAccount->fresh()->balance)->toBe(0.0)
        ->and((float) $parentMember->cashAccount->fresh()->balance)->toBe(25.0);
});

test('csv-imported dependent applications post subscription fees to their own member cash', function () {
    PublicPageSettings::save([
        ...PublicPageSettings::defaults(),
        'fee_new' => '50',
    ]);

    $parent = makePendingFeeApplication([
        'email' => 'parent@example.com',
        'name' => 'Parent',
        'membership_fee_amount' => 60,
        'membership_fee_required_amount' => 50,
        'import_arrears_cutoff_date' => '2024-06-01',
    ]);
    $parentMember = $this->approval->approve($parent);

    $dependent = makePendingFeeApplication([
        'email' => 'child@example.com',
        'name' => 'Child',
        'parent_application_id' => $parent->fresh()->id,
        'membership_fee_amount' => 80,
        'membership_fee_required_amount' => 50,
        'membership_fee_transfer_date' => '2026-01-10',
        'membership_fee_transfer_reference' => null,
        'import_arrears_cutoff_date' => '2024-06-01',
    ]);

    $childMember = $this->approval->approve($dependent);

    expect(BankTransaction::query()->where('membership_application_id', $dependent->id)->exists())->toBeTrue()
        ->and((float) $childMember->cashAccount->fresh()->balance)->toBe(30.0)
        ->and((float) $parentMember->cashAccount->fresh()->balance)->toBe(10.0);
});

test('csv-imported applications can be approved without a transfer reference', function () {
    $application = makePendingFeeApplication([
        'membership_fee_transfer_reference' => null,
        'import_arrears_cutoff_date' => '2024-06-01',
    ]);

    $member = $this->approval->approve($application);

    $bankTxn = BankTransaction::query()
        ->where('membership_application_id', $application->fresh()->id)
        ->first();

    expect($member)->not->toBeNull()
        ->and($bankTxn)->not->toBeNull()
        ->and($bankTxn->reference)->toBe(__('Application #:id', ['id' => $application->id]));
});

test('approve many collects subscription fee validation failures instead of throwing', function () {
    $application = makePendingFeeApplication([
        'membership_fee_transfer_reference' => null,
        'import_arrears_cutoff_date' => null,
    ]);

    $result = $this->approval->approveMany(collect([$application]));

    expect($result['members'])->toBeEmpty()
        ->and($result['failures'])->toHaveCount(1)
        ->and($result['failures'][0]['name'])->toBe('Jane Applicant')
        ->and($result['failures'][0]['message'])->toContain('transfer reference')
        ->and($application->fresh()->status)->toBe('pending');
});
