<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\DisbursementBatch;
use App\Models\Tenant\DisbursementBatchItem;
use App\Models\Tenant\OutboundPayment;
use App\Models\Tenant\Transaction;
use App\Models\Tenant\User;
use App\Services\BankTransactionClearanceService;
use App\Services\Disbursement\DisbursementBatchService;
use App\Services\SyntheticBankStatementFactory;
use App\Support\IbanValidator;
use App\Support\StepUpGuard;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    Account::query()->delete();
    User::query()->delete();
    OutboundPayment::query()->delete();
    DisbursementBatchItem::query()->delete();
    DisbursementBatch::query()->delete();
    BankTransaction::query()->delete();

    $this->builder = User::create([
        'name' => 'Builder',
        'email' => 'batch-builder@test.com',
        'password' => bcrypt('password'),
        'is_admin' => true,
    ]);

    $this->approver = User::create([
        'name' => 'Approver',
        'email' => 'batch-approver@test.com',
        'password' => bcrypt('password'),
        'is_admin' => true,
    ]);

    $this->service = app(DisbursementBatchService::class);
});

function makePendingOutbound(string $iban, float $amount = 100.0): OutboundPayment
{
    $statement = app(SyntheticBankStatementFactory::class)->memberCashOuts();

    $bankTxn = BankTransaction::create([
        'bank_statement_id' => $statement->id,
        'transaction_date' => now()->toDateString(),
        'description' => 'Cash out test',
        'amount' => -$amount,
        'reference' => 'OUT-'.uniqid(),
        'status' => 'imported',
        'hash' => md5(uniqid('', true)),
        'is_cleared' => false,
    ]);

    return OutboundPayment::query()->create([
        'type' => OutboundPayment::TYPE_CASH_OUT,
        'source_type' => BankTransaction::class,
        'source_id' => $bankTxn->id,
        'payee_name' => 'Payee',
        'amount' => $amount,
        'reason' => 'Test remittance',
        'instruction_date' => now()->toDateString(),
        'status' => OutboundPayment::STATUS_PENDING,
        'bank_transaction_id' => $bankTxn->id,
        'payee_iban' => $iban,
    ]);
}

test('iban validator accepts known good saudi iban', function () {
    expect(IbanValidator::isValid('SA0380000000608010167519'))->toBeTrue()
        ->and(IbanValidator::isValid('SA00INVALID'))->toBeFalse();
});

test('invalid iban remittances are excluded from eligible list', function () {
    makePendingOutbound('SA0380000000608010167519');
    makePendingOutbound('NOT-AN-IBAN');

    expect($this->service->eligibleOutboundPayments())->toHaveCount(1);
});

test('dual control rejects same user approve', function () {
    $outbound = makePendingOutbound('SA0380000000608010167519', 250);

    $batch = $this->service->createDraft($this->builder);
    $this->service->addEligibleItems($batch, [$outbound->id]);
    $this->service->submitForApproval($batch);

    $this->service->approve($batch->fresh(), $this->builder);
})->throws(InvalidArgumentException::class);

test('approve requires step-up then succeeds for other admin', function () {
    $outbound = makePendingOutbound('SA0380000000608010167519', 250);
    $batch = $this->service->createDraft($this->builder);
    $this->service->addEligibleItems($batch, [$outbound->id]);
    $this->service->submitForApproval($batch);

    $guard = app(StepUpGuard::class);
    $guard->clear();

    expect(fn () => $guard->assertConfirmed())->toThrow(InvalidArgumentException::class);

    $guard->confirm($this->approver, 'password');
    $guard->assertConfirmed();

    $approved = $this->service->approve($batch->fresh(), $this->approver);
    expect($approved->status)->toBe(DisbursementBatch::STATUS_APPROVED)
        ->and((int) $approved->approved_by)->toBe($this->approver->id);
});

test('download is audited by sha256 hash', function () {
    $outbound = makePendingOutbound('SA0380000000608010167519', 250);
    $batch = $this->service->createDraft($this->builder);
    $this->service->addEligibleItems($batch, [$outbound->id]);
    $this->service->submitForApproval($batch);
    app(StepUpGuard::class)->confirm($this->approver, 'password');
    $this->service->approve($batch->fresh(), $this->approver);
    $generated = $this->service->generateFile($batch->fresh());

    expect($generated->file_sha256)->not->toBeEmpty()
        ->and(Storage::disk('local')->exists($generated->file_disk_path))->toBeTrue();

    $meta = $this->service->downloadMeta($generated);
    expect($meta['sha256'])->toBe($generated->file_sha256);
});

test('clearing bank line marks batch item cleared without re-posting cash', function () {
    $outbound = makePendingOutbound('SA0380000000608010167519', 250);
    $batch = $this->service->createDraft($this->builder);
    $this->service->addEligibleItems($batch, [$outbound->id]);
    $this->service->submitForApproval($batch);
    app(StepUpGuard::class)->confirm($this->approver, 'password');
    $this->service->approve($batch->fresh(), $this->approver);
    $this->service->generateFile($batch->fresh());

    $txnCountBefore = Transaction::query()->count();

    app(BankTransactionClearanceService::class)->markClearedWithoutEvidence(
        $outbound->bankTransaction,
        'test clear',
    );

    $item = DisbursementBatchItem::query()->where('outbound_payment_id', $outbound->id)->first();
    expect($item?->status)->toBe(DisbursementBatchItem::STATUS_CLEARED);
    expect($batch->fresh()->status)->toBe(DisbursementBatch::STATUS_CLEARED);
    expect(Transaction::query()->count())->toBe($txnCountBefore);
});
