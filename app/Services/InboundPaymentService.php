<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\FundPosting;
use App\Models\Tenant\InboundPayment;
use App\Models\Tenant\InvestReturn;
use App\Models\Tenant\Member;
use App\Services\Tenant\MemberMembershipProfileService;
use App\Support\BusinessDay;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

final class InboundPaymentService
{
    public function __construct(
        private MemberMembershipProfileService $membershipProfiles,
        private InboundPaymentInstructionNotifier $instructionNotifier,
    ) {}

    public function recordFundPosting(
        FundPosting $posting,
        BankTransaction $bankTxn,
        ?int $createdBy = null,
        bool $notify = true,
    ): InboundPayment {
        $posting->loadMissing('member');
        $member = $posting->member;
        $payerBank = $this->payerBankSnapshot($member);

        $reason = filled($posting->comments)
            ? (string) $posting->comments
            : (filled($posting->reference)
                ? (string) $posting->reference
                : __('Deposit #:id by :name', [
                    'id' => $posting->id,
                    'name' => $member?->name ?? __('Unknown'),
                ]));

        return $this->record(
            type: InboundPayment::TYPE_DEPOSIT,
            source: $posting,
            amount: (float) $posting->amount,
            reason: $reason,
            instructionDate: $posting->posting_date ?? $bankTxn->transaction_date ?? BusinessDay::now(),
            bankTxn: $bankTxn,
            member: $member,
            payerName: $member?->name ?? __('Unknown'),
            payerIban: $payerBank['iban'],
            payerBankAccountNumber: $payerBank['bank_account_number'],
            createdBy: $createdBy,
            notify: $notify,
        );
    }

    public function recordInvestReturn(
        InvestReturn $investReturn,
        BankTransaction $bankTxn,
        ?int $createdBy = null,
        bool $notify = true,
    ): InboundPayment {
        $description = (string) $investReturn->description;
        $transactedAt = $investReturn->transacted_at ?? $bankTxn->transaction_date;

        return $this->record(
            type: InboundPayment::TYPE_INVEST_RETURN,
            source: $investReturn,
            amount: (float) $investReturn->amount,
            reason: $description,
            instructionDate: $transactedAt instanceof DateTimeInterface
                ? $transactedAt
                : BusinessDay::now(),
            bankTxn: $bankTxn,
            member: null,
            payerName: $description,
            payerIban: null,
            payerBankAccountNumber: null,
            createdBy: $createdBy,
            notify: $notify,
        );
    }

    /**
     * @param  array{
     *     payment_method: string,
     *     check_number?: string|null,
     *     payment_reference?: string|null,
     *     received_at?: CarbonInterface|DateTimeInterface|string|null,
     *     completion_notes?: string|null
     * }  $data
     */
    public function markCompleted(
        InboundPayment $payment,
        array $data,
        ?int $completedBy = null,
    ): InboundPayment {
        if (! $payment->isPending()) {
            throw new InvalidArgumentException(__('Only pending remittances can be marked completed.'));
        }

        $method = (string) ($data['payment_method'] ?? '');
        if (! array_key_exists($method, InboundPayment::paymentMethodLabels())) {
            throw new InvalidArgumentException(__('Choose a valid payment method.'));
        }

        $checkNumber = filled($data['check_number'] ?? null)
            ? trim((string) $data['check_number'])
            : null;

        if ($method === InboundPayment::METHOD_CHECK && ($checkNumber === null || $checkNumber === '')) {
            throw new InvalidArgumentException(__('Enter the check number when paying by check.'));
        }

        $receivedAt = $data['received_at'] ?? BusinessDay::now();
        if (! $receivedAt instanceof CarbonInterface) {
            $receivedAt = Carbon::parse((string) $receivedAt);
        }

        $payment->update([
            'status' => InboundPayment::STATUS_COMPLETED,
            'payment_method' => $method,
            'check_number' => $method === InboundPayment::METHOD_CHECK ? $checkNumber : null,
            'payment_reference' => filled($data['payment_reference'] ?? null)
                ? trim((string) $data['payment_reference'])
                : null,
            'received_at' => $receivedAt,
            'completion_notes' => filled($data['completion_notes'] ?? null)
                ? trim((string) $data['completion_notes'])
                : null,
            'completed_by' => $completedBy,
        ]);

        return $payment->fresh([
            'member',
            'bankTransaction',
            'creator',
            'completer',
        ]);
    }

    public function cancel(InboundPayment $payment, ?int $completedBy = null, ?string $notes = null): InboundPayment
    {
        if (! $payment->isPending()) {
            throw new InvalidArgumentException(__('Only pending remittances can be cancelled.'));
        }

        $payment->update([
            'status' => InboundPayment::STATUS_CANCELLED,
            'completed_by' => $completedBy,
            'completion_notes' => filled($notes) ? trim($notes) : null,
            'received_at' => BusinessDay::now(),
        ]);

        return $payment->fresh();
    }

    public function cancelForSource(Model $source, ?int $completedBy = null, ?string $notes = null): ?InboundPayment
    {
        $payment = InboundPayment::query()
            ->where('source_type', $source->getMorphClass())
            ->where('source_id', $source->getKey())
            ->first();

        if ($payment === null || ! $payment->isPending()) {
            return $payment;
        }

        return $this->cancel($payment, $completedBy, $notes);
    }

    public function notifyInstruction(InboundPayment $payment): void
    {
        $this->instructionNotifier->notifyCreated(
            $payment->loadMissing(['member', 'bankTransaction']),
        );
    }

    /**
     * @return array{iban: ?string, bank_account_number: ?string}
     */
    private function payerBankSnapshot(?Member $member): array
    {
        if ($member === null) {
            return ['iban' => null, 'bank_account_number' => null];
        }

        $profile = $this->membershipProfiles->findForMember($member);

        return [
            'iban' => filled($profile?->iban) ? (string) $profile->iban : null,
            'bank_account_number' => filled($profile?->bank_account_number)
                ? (string) $profile->bank_account_number
                : null,
        ];
    }

    private function record(
        string $type,
        Model $source,
        float $amount,
        string $reason,
        DateTimeInterface|CarbonInterface $instructionDate,
        BankTransaction $bankTxn,
        ?Member $member,
        string $payerName,
        ?string $payerIban,
        ?string $payerBankAccountNumber,
        ?int $createdBy,
        bool $notify,
    ): InboundPayment {
        $existing = InboundPayment::query()
            ->where('source_type', $source->getMorphClass())
            ->where('source_id', $source->getKey())
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $payment = InboundPayment::query()->create([
            'type' => $type,
            'source_type' => $source->getMorphClass(),
            'source_id' => $source->getKey(),
            'member_id' => $member?->id,
            'payer_name' => $payerName,
            'amount' => $amount,
            'reason' => $reason,
            'instruction_date' => $instructionDate,
            'status' => InboundPayment::STATUS_PENDING,
            'bank_transaction_id' => $bankTxn->id,
            'payer_iban' => $payerIban,
            'payer_bank_account_number' => $payerBankAccountNumber,
            'created_by' => $createdBy,
        ]);

        if ($notify) {
            $this->instructionNotifier->notifyCreated($payment->fresh(['member', 'bankTransaction']));
        }

        return $payment->fresh(['member', 'bankTransaction']);
    }
}
