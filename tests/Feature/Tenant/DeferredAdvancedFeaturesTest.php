<?php

declare(strict_types=1);

use App\Models\Central\Plan;
use App\Models\Tenant\Account;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\DisbursementBatch;
use App\Models\Tenant\DisbursementBatchItem;
use App\Models\Tenant\FundPosting;
use App\Models\Tenant\GatewayPayment;
use App\Models\Tenant\Meeting;
use App\Models\Tenant\Member;
use App\Models\Tenant\MemberRiskOutcome;
use App\Models\Tenant\Motion;
use App\Models\Tenant\OutboundPayment;
use App\Models\Tenant\Setting;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\Billing\SaasBillingService;
use App\Services\Disbursement\SarieAckImportService;
use App\Services\Gateway\SadadPaymentFileImportService;
use App\Services\Governance\MotionService;
use App\Services\Ocr\CloudReceiptOcrDriver;
use App\Services\Risk\MlRiskScoreService;
use App\Services\Security\PasskeyService;
use App\Services\SyntheticBankStatementFactory;
use App\Support\RiskSettings;
use App\Support\SadadSettings;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    Account::query()->delete();
    Member::query()->delete();
    User::query()->delete();
    GatewayPayment::query()->delete();
    DisbursementBatchItem::query()->delete();
    DisbursementBatch::query()->delete();
    OutboundPayment::query()->delete();
    Motion::query()->delete();
    Meeting::query()->delete();
    MemberRiskOutcome::query()->delete();
    FundPosting::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 100000, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 100000, 'is_master' => true]);
    Account::create(['type' => 'bank', 'name' => 'Master Bank', 'balance' => 0, 'is_master' => true]);

    $this->admin = User::create([
        'name' => 'Defer Admin',
        'email' => 'defer-admin@test.com',
        'password' => bcrypt('password'),
        'is_admin' => true,
    ]);

    $this->memberUser = User::create([
        'name' => 'Defer Member',
        'email' => 'defer-member@test.com',
        'password' => bcrypt('password'),
        'is_admin' => false,
    ]);

    $this->member = Member::create([
        'user_id' => $this->memberUser->id,
        'member_number' => 'MEM-DEF1',
        'name' => 'Defer Member',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYears(2),
        'status' => 'active',
        'email' => 'defer-member@test.com',
    ]);

    app(AccountingService::class)->createMemberAccounts($this->member);
});

test('sadad payment file import marks pending payment paid', function () {
    SadadSettings::save([
        'enabled' => true,
        'biller_id' => 'BILLER1',
        'merchant_code' => 'M1',
        'registration_status' => 'active',
    ]);

    $payment = GatewayPayment::query()->create([
        'member_id' => $this->member->id,
        'purpose' => GatewayPayment::PURPOSE_CONTRIBUTION,
        'amount' => 100,
        'currency' => 'SAR',
        'status' => GatewayPayment::STATUS_PENDING,
        'provider' => GatewayPayment::PROVIDER_SADAD,
        'provider_ref' => 'sadad_test_ref_1',
    ]);

    $csv = "provider_ref,amount,status,paid_at,member_number\nsadad_test_ref_1,100,paid,," . $this->member->member_number . "\n";
    $result = app(SadadPaymentFileImportService::class)->import($csv, $this->admin);

    expect($result['paid'])->toBe(1)
        ->and($payment->fresh()->status)->toBe(GatewayPayment::STATUS_PAID);
});

test('sarie ack import accepts and rejects batch items', function () {
    $batch = DisbursementBatch::query()->create([
        'status' => DisbursementBatch::STATUS_GENERATED,
        'bank_format' => DisbursementBatch::FORMAT_AL_RAJHI_CSV,
        'created_by' => $this->admin->id,
        'item_count' => 2,
        'total_amount' => 200,
        'generated_at' => now(),
        'file_disk_path' => 'disbursement-batches/x.csv',
        'file_sha256' => hash('sha256', 'x'),
    ]);

    $makeOutbound = function (string $name) {
        $statement = app(SyntheticBankStatementFactory::class)->memberCashOuts();
        $bankTxn = BankTransaction::query()->create([
            'bank_statement_id' => $statement->id,
            'transaction_date' => now()->toDateString(),
            'description' => "Outbound {$name}",
            'amount' => -100,
            'reference' => 'OUT-' . uniqid($name, true),
            'status' => 'imported',
            'hash' => md5(uniqid($name, true)),
            'is_cleared' => false,
        ]);

        return OutboundPayment::query()->create([
            'type' => OutboundPayment::TYPE_CASH_OUT,
            'source_type' => BankTransaction::class,
            'source_id' => $bankTxn->id,
            'payee_name' => $name,
            'amount' => 100,
            'reason' => "SARIE test {$name}",
            'status' => OutboundPayment::STATUS_PENDING,
            'bank_transaction_id' => $bankTxn->id,
            'payee_iban' => 'SA0380000000608010167519',
            'instruction_date' => now()->toDateString(),
        ]);
    };

    $outboundA = $makeOutbound('A');
    $outboundB = $makeOutbound('B');

    $itemA = DisbursementBatchItem::query()->create([
        'disbursement_batch_id' => $batch->id,
        'outbound_payment_id' => $outboundA->id,
        'amount' => 100,
        'payee_name' => 'A',
        'payee_iban' => 'SA0380000000608010167519',
        'status' => DisbursementBatchItem::STATUS_INCLUDED,
    ]);
    $itemB = DisbursementBatchItem::query()->create([
        'disbursement_batch_id' => $batch->id,
        'outbound_payment_id' => $outboundB->id,
        'amount' => 100,
        'payee_name' => 'B',
        'payee_iban' => 'SA0380000000608010167519',
        'status' => DisbursementBatchItem::STATUS_INCLUDED,
    ]);

    $csv = "item_id,status,reference,reason\n{$itemA->id},accepted,ACK1,\n{$itemB->id},rejected,ACK2,insufficient funds\n";
    $result = app(SarieAckImportService::class)->import($batch, $csv);

    expect($result['accepted'])->toBe(1)
        ->and($result['rejected'])->toBe(1)
        ->and($batch->fresh()->status)->toBe(DisbursementBatch::STATUS_ACKED)
        ->and($itemB->fresh()->status)->toBe(DisbursementBatchItem::STATUS_REJECTED)
        ->and($itemA->fresh()->ack_status)->toBe('accepted');
});

test('proxy voting casts grantor ballot via proxy member', function () {
    $proxyUser = User::create([
        'name' => 'Proxy',
        'email' => 'proxy@test.com',
        'password' => bcrypt('password'),
        'is_admin' => false,
    ]);
    $proxy = Member::create([
        'user_id' => $proxyUser->id,
        'member_number' => 'MEM-PROXY',
        'name' => 'Proxy',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYears(2),
        'status' => 'active',
        'email' => 'proxy@test.com',
    ]);

    $motions = app(MotionService::class);
    $meeting = $motions->createMeeting('Proxy AGM', $this->admin);
    $meeting->forceFill(['status' => Meeting::STATUS_OPEN])->save();
    $motion = $motions->createMotion($meeting, 'Fee change', Motion::TYPE_GENERIC);
    $motions->openMotionForVoting($motion);

    $motions->grantProxy($motion->fresh(), $this->member, $proxy);
    $vote = $motions->castProxyVote($motion->fresh(), $proxy, $this->member, 'yes', 'Absentee');

    expect($vote->member_id)->toBe($this->member->id)
        ->and($vote->cast_by_member_id)->toBe($proxy->id)
        ->and($vote->choice)->toBe('yes');
});

test('cloud ocr driver posts to configured endpoint', function () {
    config()->set('ocr.cloud.endpoint', 'https://ocr.test/extract');
    config()->set('ocr.cloud.api_key', 'ocr-key');

    Http::fake([
        'ocr.test/*' => Http::response([
            'amount' => 123.45,
            'date' => '2026-09-01',
            'iban' => 'SA0380000000608010167519',
            'reference' => 'R1',
            'confidence' => 0.91,
        ], 200),
    ]);

    $posting = FundPosting::query()->create([
        'member_id' => $this->member->id,
        'amount' => 100,
        'status' => 'pending',
        'posting_date' => now()->toDateString(),
        'reference' => 'x',
    ]);

    $result = app(CloudReceiptOcrDriver::class)->extract($posting);

    expect($result->amount)->toBe(123.45)
        ->and($result->confidence)->toBe(0.91)
        ->and($result->iban)->toBe('SA0380000000608010167519');
});

test('ml risk records outcomes and blends when enabled', function () {
    Setting::set(RiskSettings::GROUP, 'ml_enabled', '1');
    Setting::set(RiskSettings::GROUP, 'ml_min_labels', '5');
    Setting::set(RiskSettings::GROUP, 'ml_blend_weight', '0.5');

    $ml = app(MlRiskScoreService::class);
    for ($i = 0; $i < 6; $i++) {
        $ml->recordOutcome($this->member, $i % 2 === 0);
    }

    $score = $ml->score($this->member->fresh());

    expect($score['source'])->toBe('ml_blend')
        ->and($score['ml_probability'])->not->toBeNull()
        ->and($score['score'])->toBeGreaterThanOrEqual(0)
        ->and($score['score'])->toBeLessThanOrEqual(100);
});

test('software passkey can register and soft-assert', function () {
    $passkeys = app(PasskeyService::class);
    $registered = $passkeys->registerSoftwarePasskey($this->admin);

    expect($registered['credential']->user_id)->toBe($this->admin->id);

    $ok = $passkeys->completeAssertion($this->admin->fresh(), [
        'id' => $registered['credential']->credential_id,
        'clientDataJSON' => base64_encode(json_encode([
            'type' => 'webauthn.get',
            'challenge' => $registered['challenge'],
            'origin' => 'http://testing.localhost',
        ], JSON_THROW_ON_ERROR)),
        'authenticatorData' => base64_encode('authdata'),
        'signature' => $registered['signature'],
    ]);

    expect($ok)->toBeTrue()
        ->and($registered['credential']->fresh()->sign_count)->toBe(1);
});

test('saas billing creates invoice and fake checkout activates subscription', function () {
    $tenant = tenant();
    $plan = Plan::query()->firstOrCreate(
        ['slug' => 'saas-checkout-test'],
        [
            'name' => 'Checkout Test',
            'price' => 99,
            'currency' => 'SAR',
            'billing_cycle' => 'monthly',
            'duration_months' => 1,
            'description' => 'Test',
            'data' => ['features' => ['api']],
            'is_active' => true,
        ],
    );

    $billing = app(SaasBillingService::class);
    $invoice = $billing->createInvoice($tenant, $plan);
    expect($invoice->status)->toBe('pending');

    $paid = $billing->checkout($invoice);
    expect($paid->status)->toBe('paid')
        ->and($tenant->fresh()->subscription?->isActive())->toBeTrue();
});

test('write api creates gateway payment with payments:write ability', function () {
    $tenant = tenant();
    if ($tenant !== null && !$tenant->domains()->where('domain', 'testing.localhost')->exists()) {
        $tenant->domains()->create(['domain' => 'testing.localhost']);
    }

    $token = $this->admin->issueApiToken('writes', ['payments:write']);

    $this->withToken($token['plain_text'])
        ->postJson('http://testing.localhost/api/v1/gateway-payments', [
            'member_id' => $this->member->id,
            'purpose' => GatewayPayment::PURPOSE_CONTRIBUTION,
            'amount' => 50,
        ])
        ->assertCreated()
        ->assertJsonPath('data.purpose', GatewayPayment::PURPOSE_CONTRIBUTION)
        ->assertJsonPath('data.amount', 50);

    $readOnly = $this->admin->issueApiToken('reads', ['members:read']);
    $this->withToken($readOnly['plain_text'])
        ->postJson('http://testing.localhost/api/v1/gateway-payments', [
            'member_id' => $this->member->id,
            'purpose' => GatewayPayment::PURPOSE_CONTRIBUTION,
            'amount' => 50,
        ])
        ->assertForbidden();
});
