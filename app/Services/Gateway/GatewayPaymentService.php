<?php

declare(strict_types=1);

namespace App\Services\Gateway;

use App\Contracts\PaymentGatewayAdapter;
use App\Models\Tenant\GatewayPayment;
use App\Models\Tenant\Member;
use App\Support\Gateway\GatewayWebhookEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class GatewayPaymentService
{
    public function __construct(
        private readonly GatewayAdapterResolver $resolver,
        private readonly GatewayPaymentPostingService $posting,
        private readonly ?PaymentGatewayAdapter $adapter = null,
    ) {}

    private function adapter(): PaymentGatewayAdapter
    {
        return $this->adapter ?? $this->resolver->driver();
    }

    /**
     * Create a payment intent locked to an exact owed amount.
     *
     * @throws InvalidArgumentException when amount is not positive or mismatches expected
     */
    public function createIntent(
        Member $member,
        string $purpose,
        float $amount,
        ?float $expectedOwed = null,
        ?Model $payable = null,
        array $metadata = [],
    ): GatewayPayment {
        $amount = round($amount, 2);

        if ($amount <= 0) {
            throw new InvalidArgumentException(__('Payment amount must be greater than zero.'));
        }

        if ($expectedOwed !== null && abs(round($expectedOwed, 2) - $amount) > 0.001) {
            throw new InvalidArgumentException(__('Payment amount must equal the exact amount owed.'));
        }

        $purposes = [
            GatewayPayment::PURPOSE_CONTRIBUTION,
            GatewayPayment::PURPOSE_EMI,
            GatewayPayment::PURPOSE_FEE,
            GatewayPayment::PURPOSE_DEPOSIT,
            GatewayPayment::PURPOSE_ARREARS,
        ];

        if (! in_array($purpose, $purposes, true)) {
            throw new InvalidArgumentException(__('Invalid payment purpose.'));
        }

        $adapter = $this->adapter();

        return DB::transaction(function () use ($member, $purpose, $amount, $payable, $metadata, $adapter): GatewayPayment {
            $payment = GatewayPayment::query()->create([
                'member_id' => $member->id,
                'purpose' => $purpose,
                'amount' => $amount,
                'currency' => (string) config('gateway.currency', 'SAR'),
                'status' => GatewayPayment::STATUS_PENDING,
                'provider' => $adapter->driver(),
                'payable_type' => $payable?->getMorphClass(),
                'payable_id' => $payable?->getKey(),
                'metadata' => $metadata,
            ]);

            $checkout = $adapter->createPayment($payment);

            $payment->forceFill([
                'provider_ref' => $checkout->providerRef,
                'checkout_url' => $checkout->checkoutUrl,
                'client_secret' => $checkout->clientSecret,
                'metadata' => array_merge($payment->metadata ?? [], $checkout->metadata),
            ])->save();

            return $payment->fresh() ?? $payment;
        });
    }

    public function handleWebhookEvent(GatewayWebhookEvent $event, ?PaymentGatewayAdapter $adapter = null): GatewayPayment
    {
        $adapter ??= $this->adapter();

        $payment = GatewayPayment::query()
            ->where('provider', $adapter->driver())
            ->where('provider_ref', $event->providerRef)
            ->first();

        if ($payment === null) {
            throw new InvalidArgumentException(__('Unknown gateway payment reference.'));
        }

        if ($event->isPaid()) {
            return $this->posting->markPaid($payment, $event->payload);
        }

        if ($event->isFailed()) {
            if ($payment->isPending()) {
                $payment->forceFill(['status' => GatewayPayment::STATUS_FAILED])->save();
            }

            return $payment->fresh() ?? $payment;
        }

        if ($event->status === 'cancelled' && $payment->isPending()) {
            $payment->forceFill(['status' => GatewayPayment::STATUS_CANCELLED])->save();
        }

        return $payment->fresh() ?? $payment;
    }

    public function completeFake(GatewayPayment $payment): GatewayPayment
    {
        if ($payment->provider !== GatewayPayment::PROVIDER_FAKE) {
            throw new InvalidArgumentException(__('Only fake gateway payments can be completed this way.'));
        }

        if (! $payment->isPending()) {
            return $payment;
        }

        return $this->posting->markPaid($payment, ['fake_complete' => true]);
    }
}
