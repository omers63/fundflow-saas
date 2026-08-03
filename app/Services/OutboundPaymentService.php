<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\CashOutRequest;
use App\Models\Tenant\ExpenseDisbursement;
use App\Models\Tenant\FeeDisbursement;
use App\Models\Tenant\InvestDisbursement;
use App\Models\Tenant\Member;
use App\Models\Tenant\OutboundPayment;
use App\Services\Tenant\MemberMembershipProfileService;
use App\Support\BusinessDay;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

final class OutboundPaymentService
{
    public function __construct(
        private MemberMembershipProfileService $membershipProfiles,
        private OutboundPaymentInstructionNotifier $instructionNotifier,
    ) {}

    public function recordCashOut(
        CashOutRequest $request,
        BankTransaction $bankTxn,
        ?int $createdBy = null,
        ?CarbonInterface $instructionDate = null,
        bool $notify = true,
    ): OutboundPayment {
        $request->loadMissing('member');

        $member = $request->member;
        $payeeBank = $this->payeeBankSnapshot($member);
        $reason = filled($request->notes)
            ? (string) $request->notes
            : __('Cash out #:id – :name', [
                'id' => $request->id,
                'name' => $member?->name ?? __('Unknown'),
            ]);

        return $this->record(
            type: OutboundPayment::TYPE_CASH_OUT,
            source: $request,
            amount: (float) $request->amount,
            reason: $reason,
            instructionDate: $instructionDate ?? $bankTxn->transaction_date ?? BusinessDay::now(),
            bankTxn: $bankTxn,
            member: $member,
            payeeName: $member?->name ?? __('Unknown'),
            payeeIban: $payeeBank['iban'],
            payeeBankAccountNumber: $payeeBank['bank_account_number'],
            createdBy: $createdBy,
            notify: $notify,
        );
    }

    public function recordExpenseDisbursement(
        ExpenseDisbursement $disbursement,
        BankTransaction $bankTxn,
        ?int $createdBy = null,
        bool $notify = true,
    ): OutboundPayment {
        return $this->recordMasterDisbursement(
            OutboundPayment::TYPE_EXPENSE_OUT,
            $disbursement,
            $bankTxn,
            $createdBy,
            $notify,
        );
    }

    public function recordFeeDisbursement(
        FeeDisbursement $disbursement,
        BankTransaction $bankTxn,
        ?int $createdBy = null,
        bool $notify = true,
    ): OutboundPayment {
        return $this->recordMasterDisbursement(
            OutboundPayment::TYPE_FEE_OUT,
            $disbursement,
            $bankTxn,
            $createdBy,
            $notify,
        );
    }

    public function recordInvestDisbursement(
        InvestDisbursement $disbursement,
        BankTransaction $bankTxn,
        ?int $createdBy = null,
        bool $notify = true,
    ): OutboundPayment {
        return $this->recordMasterDisbursement(
            OutboundPayment::TYPE_INVEST_OUT,
            $disbursement,
            $bankTxn,
            $createdBy,
            $notify,
        );
    }

    /**
     * @param  array{
     *     payment_method: string,
     *     check_number?: string|null,
     *     payment_reference?: string|null,
     *     paid_at?: CarbonInterface|DateTimeInterface|string|null,
     *     completion_notes?: string|null
     * }  $data
     */
    public function markCompleted(
        OutboundPayment $payment,
        array $data,
        ?int $completedBy = null,
    ): OutboundPayment {
        if (! $payment->isPending()) {
            throw new InvalidArgumentException(__('Only pending remittances can be marked completed.'));
        }

        $method = (string) ($data['payment_method'] ?? '');
        if (! array_key_exists($method, OutboundPayment::paymentMethodLabels())) {
            throw new InvalidArgumentException(__('Choose a valid payment method.'));
        }

        $checkNumber = filled($data['check_number'] ?? null)
            ? trim((string) $data['check_number'])
            : null;

        if ($method === OutboundPayment::METHOD_CHECK && ($checkNumber === null || $checkNumber === '')) {
            throw new InvalidArgumentException(__('Enter the check number when paying by check.'));
        }

        $paidAt = $data['paid_at'] ?? BusinessDay::now();
        if (! $paidAt instanceof CarbonInterface) {
            $paidAt = Carbon::parse((string) $paidAt);
        }

        $payment->update([
            'status' => OutboundPayment::STATUS_COMPLETED,
            'payment_method' => $method,
            'check_number' => $method === OutboundPayment::METHOD_CHECK ? $checkNumber : null,
            'payment_reference' => filled($data['payment_reference'] ?? null)
                ? trim((string) $data['payment_reference'])
                : null,
            'paid_at' => $paidAt,
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

    public function cancel(OutboundPayment $payment, ?int $completedBy = null, ?string $notes = null): OutboundPayment
    {
        if (! $payment->isPending()) {
            throw new InvalidArgumentException(__('Only pending remittances can be cancelled.'));
        }

        $payment->update([
            'status' => OutboundPayment::STATUS_CANCELLED,
            'completed_by' => $completedBy,
            'completion_notes' => filled($notes) ? trim($notes) : null,
            'paid_at' => BusinessDay::now(),
        ]);

        return $payment->fresh();
    }

    public function notifyInstruction(OutboundPayment $payment): void
    {
        $this->instructionNotifier->notifyCreated(
            $payment->loadMissing(['member', 'bankTransaction']),
        );
    }

    /**
     * @return array{iban: ?string, bank_account_number: ?string}
     */
    private function payeeBankSnapshot(?Member $member): array
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

    private function recordMasterDisbursement(
        string $type,
        Model $disbursement,
        BankTransaction $bankTxn,
        ?int $createdBy,
        bool $notify,
    ): OutboundPayment {
        $amount = (float) $disbursement->getAttribute('amount');
        $description = (string) $disbursement->getAttribute('description');
        $transactedAt = $disbursement->getAttribute('transacted_at') ?? $bankTxn->transaction_date;

        return $this->record(
            type: $type,
            source: $disbursement,
            amount: $amount,
            reason: $description,
            instructionDate: $transactedAt instanceof DateTimeInterface
                ? $transactedAt
                : BusinessDay::now(),
            bankTxn: $bankTxn,
            member: null,
            payeeName: $description,
            payeeIban: null,
            payeeBankAccountNumber: null,
            createdBy: $createdBy,
            notify: $notify,
        );
    }

    private function record(
        string $type,
        Model $source,
        float $amount,
        string $reason,
        DateTimeInterface|CarbonInterface $instructionDate,
        BankTransaction $bankTxn,
        ?Member $member,
        string $payeeName,
        ?string $payeeIban,
        ?string $payeeBankAccountNumber,
        ?int $createdBy,
        bool $notify,
    ): OutboundPayment {
        $existing = OutboundPayment::query()
            ->where('source_type', $source->getMorphClass())
            ->where('source_id', $source->getKey())
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $payment = OutboundPayment::query()->create([
            'type' => $type,
            'source_type' => $source->getMorphClass(),
            'source_id' => $source->getKey(),
            'member_id' => $member?->id,
            'payee_name' => $payeeName,
            'amount' => $amount,
            'reason' => $reason,
            'instruction_date' => $instructionDate,
            'status' => OutboundPayment::STATUS_PENDING,
            'bank_transaction_id' => $bankTxn->id,
            'payee_iban' => $payeeIban,
            'payee_bank_account_number' => $payeeBankAccountNumber,
            'created_by' => $createdBy,
        ]);

        if ($notify) {
            $this->instructionNotifier->notifyCreated($payment->fresh(['member', 'bankTransaction']));
        }

        return $payment->fresh(['member', 'bankTransaction']);
    }
}
