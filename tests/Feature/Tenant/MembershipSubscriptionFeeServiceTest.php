<?php

use App\Models\Tenant\Account;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\MembershipApplication;
use App\Services\AccountingService;
use App\Services\FundPostingService;
use App\Services\MembershipApplicationApprovalService;
use App\Services\MembershipSubscriptionFeeService;
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

    expect(fn() => $this->approval->approve($application))
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
    $application = makePendingFeeApplication();

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
        ->and($bankTxn->master_cash_transaction_id)->not->toBeNull();
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

test('dependent applications skip subscription fee posting', function () {
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
