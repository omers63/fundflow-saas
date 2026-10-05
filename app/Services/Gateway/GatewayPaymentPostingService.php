<?php

declare(strict_types=1);

namespace App\Services\Gateway;

use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\FundPosting;
use App\Models\Tenant\GatewayPayment;
use App\Models\Tenant\InboundPayment;
use App\Notifications\Tenant\GenericMemberAlertNotification;
use App\Services\AccountingService;
use App\Services\OperationalReviewWorkflowService;
use App\Services\SyntheticBankStatementFactory;
use App\Services\Webhooks\WebhookDispatcher;
use App\Support\BankStatementBuckets;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class GatewayPaymentPostingService
{
    public function __construct(
        private readonly AccountingService $accounting,
        private readonly SyntheticBankStatementFactory $syntheticStatements,
        private readonly OperationalReviewWorkflowService $reviewWorkflow,
    ) {}

    /**
     * Idempotently mark a gateway payment paid and post cash + cleared bank evidence.
     *
     * @param  array<string, mixed>  $payload
     */
    public function markPaid(GatewayPayment $payment, array $payload = []): GatewayPayment
    {
        return DB::transaction(function () use ($payment, $payload): GatewayPayment {
            /** @var GatewayPayment $locked */
            $locked = GatewayPayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($locked->isPaid()) {
                return $locked;
            }

            if (! $locked->isPending()) {
                throw new InvalidArgumentException(__('Only pending gateway payments can be marked paid.'));
            }

            $locked->loadMissing('member.cashAccount');
            $member = $locked->member;

            if ($member === null || $member->cashAccount === null) {
                throw new InvalidArgumentException(__('Member cash account is required for gateway posting.'));
            }

            $amount = (float) $locked->amount;
            $description = __('Gateway payment #:id (:purpose)', [
                'id' => $locked->id,
                'purpose' => $locked->purpose,
            ]);

            $this->accounting->creditMemberCashWithMasterMirror(
                $member->cashAccount,
                $amount,
                __('Posted: :description', ['description' => $description]),
                __('(gateway mirror)'),
                $locked,
                null,
                $member->id,
            );

            $statement = $this->syntheticStatements->forFilename(BankStatementBuckets::GATEWAY_PAYMENTS);
            $now = now();

            $bankTxn = BankTransaction::query()->create([
                'bank_statement_id' => $statement->id,
                'transaction_date' => $now->toDateString(),
                'description' => $description,
                'amount' => $amount,
                'reference' => (string) ($locked->provider_ref ?? $locked->id),
                'status' => 'imported',
                'member_id' => $member->id,
                'hash' => md5("gateway-{$locked->id}-{$locked->provider_ref}-{$amount}"),
                'is_cleared' => true,
                'cleared_at' => $now,
            ]);

            InboundPayment::query()->updateOrCreate(
                [
                    'source_type' => $locked->getMorphClass(),
                    'source_id' => $locked->id,
                ],
                [
                    'type' => InboundPayment::TYPE_GATEWAY,
                    'member_id' => $member->id,
                    'payer_name' => $member->name,
                    'amount' => $amount,
                    'reason' => $description,
                    'instruction_date' => $now->toDateString(),
                    'status' => InboundPayment::STATUS_COMPLETED,
                    'bank_transaction_id' => $bankTxn->id,
                    'payment_method' => InboundPayment::METHOD_GATEWAY,
                    'payment_reference' => (string) ($locked->provider_ref ?? ''),
                    'received_at' => $now,
                    'completion_notes' => __('Cleared via payment gateway'),
                ],
            );

            $locked->forceFill([
                'status' => GatewayPayment::STATUS_PAID,
                'posted_at' => $now,
                'bank_transaction_id' => $bankTxn->id,
                'metadata' => array_merge($locked->metadata ?? [], [
                    'webhook' => $payload,
                ]),
            ])->save();

            $this->settleLinkedDepositIfNeeded($locked);

            $locked->loadMissing('member.user');
            if ($locked->member?->user !== null) {
                $locked->member->user->notify(new GenericMemberAlertNotification(
                    title: __('Payment completed'),
                    body: __('Gateway payment of :amount was credited to your cash account.', [
                        'amount' => number_format($amount, 2),
                    ]),
                ));
            }

            try {
                app(WebhookDispatcher::class)->dispatch('gateway.payment.paid', [
                    'payment_id' => $locked->id,
                    'member_id' => $locked->member_id,
                    'amount' => $amount,
                    'purpose' => $locked->purpose,
                ]);
            } catch (\Throwable $e) {
                report($e);
            }

            return $locked->fresh() ?? $locked;
        });
    }

    private function settleLinkedDepositIfNeeded(GatewayPayment $payment): void
    {
        if ($payment->purpose !== GatewayPayment::PURPOSE_DEPOSIT) {
            return;
        }

        $payable = $payment->payable;

        if (! $payable instanceof FundPosting || $payable->status !== 'pending') {
            return;
        }

        $this->reviewWorkflow->markReviewed(
            $payable,
            'accepted',
            null,
            __('Accepted via payment gateway #:id', ['id' => $payment->id]),
        );

        if ($payable->bank_transaction_id) {
            BankTransaction::query()
                ->whereKey($payable->bank_transaction_id)
                ->update(['status' => 'ignored']);
        }
    }
}
