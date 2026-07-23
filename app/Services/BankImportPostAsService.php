<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Account;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\Member;
use App\Support\BankTransactionWorkflow;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Bank-file-first posting: record a domain operation from an imported CSV line
 * and clear the resulting ops clearance row against that line in one step.
 */
final class BankImportPostAsService
{
    public const TYPE_MEMBER_DEPOSIT = 'member_deposit';

    public const TYPE_INVEST_RETURN = 'invest_return';

    public const TYPE_CASH_OUT = 'cash_out';

    public const TYPE_EXPENSE_OUT = 'expense_out';

    public const TYPE_FEE_OUT = 'fee_out';

    public const TYPE_INVEST_OUT = 'invest_out';

    public function __construct(
        private FundFlowService $fundFlow,
        private MasterInvestInService $investIn,
        private MasterInvestOutService $investOut,
        private MasterExpenseDisbursementService $expenseDisbursements,
        private MasterFeeDisbursementService $feeDisbursements,
        private MemberCashOutService $cashOuts,
        private BankClearingMatchService $matching,
    ) {}

    /**
     * @return array<string, string>
     */
    public static function typeOptionsForAmount(float $amount): array
    {
        if ($amount >= 0) {
            return [
                self::TYPE_MEMBER_DEPOSIT => __('Member deposit'),
                self::TYPE_INVEST_RETURN => __('Invest return'),
            ];
        }

        return [
            self::TYPE_CASH_OUT => __('Cash-out'),
            self::TYPE_EXPENSE_OUT => __('Expense out'),
            self::TYPE_FEE_OUT => __('Fee out'),
            self::TYPE_INVEST_OUT => __('Invest out'),
        ];
    }

    public static function requiresMember(string $type): bool
    {
        return in_array($type, [self::TYPE_MEMBER_DEPOSIT, self::TYPE_CASH_OUT], true);
    }

    public static function canPostAs(BankTransaction $imported): bool
    {
        return BankTransactionWorkflow::canPostToMember($imported);
    }

    public function postAs(
        BankTransaction $imported,
        string $type,
        string $description,
        ?int $memberId = null,
        ?string $transactionDate = null,
    ): void {
        if (! self::canPostAs($imported)) {
            throw new InvalidArgumentException(__('This statement line cannot be posted as an operation.'));
        }

        $amount = (float) $imported->amount;
        $options = self::typeOptionsForAmount($amount);

        if (! array_key_exists($type, $options)) {
            throw new InvalidArgumentException(__('Choose a posting type that matches this line’s credit or debit.'));
        }

        $description = trim($description);

        if ($description === '') {
            $description = FundFlowService::resolveBankLineDetail($imported);
        }

        $date = filled($transactionDate)
            ? Carbon::parse($transactionDate)
            : Carbon::parse((string) $imported->transaction_date);

        $absolute = abs($amount);

        if ($absolute < 0.00001) {
            throw new InvalidArgumentException(__('Amount must be greater than zero.'));
        }

        if ($type === self::TYPE_MEMBER_DEPOSIT) {
            $this->postMemberDeposit($imported, $memberId, $date);

            return;
        }

        ReconciliationService::withoutRealtimeChecks(function () use (
            $imported,
            $type,
            $description,
            $memberId,
            $date,
            $absolute,
        ): void {
            DB::transaction(function () use (
                $imported,
                $type,
                $description,
                $memberId,
                $date,
                $absolute,
            ): void {
                $opsLine = match ($type) {
                    self::TYPE_INVEST_RETURN => $this->createInvestReturnLine($absolute, $description, $date),
                    self::TYPE_INVEST_OUT => $this->createInvestOutLine($absolute, $description, $date),
                    self::TYPE_EXPENSE_OUT => $this->createExpenseOutLine($absolute, $description, $date),
                    self::TYPE_FEE_OUT => $this->createFeeOutLine($absolute, $description, $date),
                    self::TYPE_CASH_OUT => $this->createCashOutLine($absolute, $description, $date, $memberId),
                    default => throw new InvalidArgumentException(__('Unsupported posting type.')),
                };

                $this->matching->clearMatchPair($opsLine->fresh(), $imported->fresh());
            });
        });
    }

    private function postMemberDeposit(BankTransaction $imported, ?int $memberId, Carbon $date): void
    {
        $member = $this->requireMember($memberId);

        AccountingService::withoutMemberCashCollection(
            fn () => $this->fundFlow->ensureMirroredAndPostToMember($imported, $member, $date),
        );
    }

    private function createInvestReturnLine(float $amount, string $description, Carbon $date): BankTransaction
    {
        $account = Account::masterInvest();

        if ($account === null) {
            throw new InvalidArgumentException(__('Required master account is not configured.'));
        }

        $investReturn = $this->investIn->investIn($account, $amount, $description, $date);

        return $this->requireOpsBankLine($investReturn->fresh(['bankTransaction'])->bankTransaction);
    }

    private function createInvestOutLine(float $amount, string $description, Carbon $date): BankTransaction
    {
        $account = Account::masterInvest();

        if ($account === null) {
            throw new InvalidArgumentException(__('Required master account is not configured.'));
        }

        $disbursement = $this->investOut->investOut($account, $amount, $description, $date);

        return $this->requireOpsBankLine($disbursement->fresh(['bankTransaction'])->bankTransaction);
    }

    private function createExpenseOutLine(float $amount, string $description, Carbon $date): BankTransaction
    {
        $account = Account::masterExpense();

        if ($account === null) {
            throw new InvalidArgumentException(__('Required master account is not configured.'));
        }

        $disbursement = $this->expenseDisbursements->disburse($account, $amount, $description, $date);

        return $this->requireOpsBankLine($disbursement->fresh(['bankTransaction'])->bankTransaction);
    }

    private function createFeeOutLine(float $amount, string $description, Carbon $date): BankTransaction
    {
        $account = Account::masterFees();

        if ($account === null) {
            throw new InvalidArgumentException(__('Required master account is not configured.'));
        }

        $disbursement = $this->feeDisbursements->disburse($account, $amount, $description, $date);

        return $this->requireOpsBankLine($disbursement->fresh(['bankTransaction'])->bankTransaction);
    }

    private function createCashOutLine(
        float $amount,
        string $description,
        Carbon $date,
        ?int $memberId,
    ): BankTransaction {
        $member = $this->requireMember($memberId);

        return MemberCashOutService::withoutNotifications(function () use ($member, $amount, $description, $date): BankTransaction {
            $request = $this->cashOuts->submit($member, $amount, $description);

            $this->cashOuts->accept(
                $request->fresh(),
                Auth::guard('tenant')->id(),
                __('Posted from bank import'),
                $date,
            );

            return $this->requireOpsBankLine($request->fresh(['bankTransaction'])->bankTransaction);
        });
    }

    private function requireMember(?int $memberId): Member
    {
        if ($memberId === null) {
            throw new InvalidArgumentException(__('Choose a member for this posting type.'));
        }

        $member = Member::query()->find($memberId);

        if ($member === null) {
            throw new InvalidArgumentException(__('Choose a member for this posting type.'));
        }

        return $member;
    }

    private function requireOpsBankLine(?BankTransaction $line): BankTransaction
    {
        if ($line === null) {
            throw new InvalidArgumentException(__('Could not create the operational bank line.'));
        }

        return $line;
    }
}
