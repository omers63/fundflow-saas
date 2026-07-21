<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Account;
use App\Models\Tenant\BankStatement;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\Member;
use App\Support\BankStatementBuckets;
use App\Support\BusinessDay;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ManualBankStatementLineService
{
    public const KIND_CREDIT = 'credit';

    public const KIND_DEBIT = 'debit';

    public function __construct(
        private FundPostingService $fundPostings,
        private MemberCashOutService $cashOuts,
        private MasterExpenseDisbursementService $expenseDisbursements,
        private MasterFeeDisbursementService $feeDisbursements,
        private MasterInvestDisbursementService $investDisbursements,
        private MasterInvestReturnService $investReturns,
        private SyntheticBankStatementFactory $syntheticStatements,
    ) {}

    /**
     * @return array<string, string>
     */
    public static function kindOptions(): array
    {
        return [
            self::KIND_CREDIT => __('Credit (inbound)'),
            self::KIND_DEBIT => __('Debit (outbound)'),
        ];
    }

    /**
     * Optional labels stored on {@see BankTransaction::$transaction_type}.
     *
     * @return array<string, string>
     */
    public static function typeOptions(): array
    {
        return [
            'transfer_in' => __('Transfer in'),
            'transfer_out' => __('Transfer out'),
            'deposit' => __('Deposit'),
            'cash_withdrawal' => __('Cash withdrawal'),
            'fee' => __('Bank fee'),
            'other' => __('Other'),
        ];
    }

    public static function supportsManualLines(BankStatement $statement): bool
    {
        return ! in_array($statement->filename, BankStatementBuckets::MEMBERSHIP_IMPORT_PLACEHOLDERS, true);
    }

    public static function isOperationalClearance(BankStatement $statement): bool
    {
        return in_array($statement->filename, BankStatementBuckets::OPERATIONAL_CLEARANCE, true);
    }

    public static function requiresMember(BankStatement $statement): bool
    {
        return in_array($statement->filename, [
            BankStatementBuckets::MEMBER_POSTINGS,
            BankStatementBuckets::MEMBER_CASH_OUTS,
        ], true);
    }

    /**
     * Fixed credit/debit for operational buckets; null means the user chooses.
     */
    public static function fixedKind(BankStatement $statement): ?string
    {
        return match ($statement->filename) {
            BankStatementBuckets::MEMBER_POSTINGS,
            BankStatementBuckets::MASTER_INVEST_RETURNS => self::KIND_CREDIT,
            BankStatementBuckets::MEMBER_CASH_OUTS,
            BankStatementBuckets::MASTER_EXPENSE_DISBURSEMENTS,
            BankStatementBuckets::MASTER_FEE_DISBURSEMENTS,
            BankStatementBuckets::MASTER_INVEST_DISBURSEMENTS => self::KIND_DEBIT,
            default => null,
        };
    }

    public function create(
        BankStatement $statement,
        string $kind,
        float $amount,
        string $description,
        CarbonInterface|string $transactionDate,
        ?string $reference = null,
        ?string $transactionType = null,
        ?int $memberId = null,
    ): BankTransaction {
        if (! self::supportsManualLines($statement)) {
            throw new InvalidArgumentException(__('Manual lines cannot be added to membership import statements.'));
        }

        $absolute = abs($amount);

        if ($absolute < 0.00001) {
            throw new InvalidArgumentException(__('Amount must be greater than zero.'));
        }

        $date = $transactionDate instanceof CarbonInterface
            ? $transactionDate->toDateString()
            : (string) $transactionDate;

        if (self::isOperationalClearance($statement)) {
            return $this->createOperational(
                $statement,
                $absolute,
                trim($description),
                $date,
                $reference,
                $memberId,
            );
        }

        return $this->createImportedLine(
            $statement,
            $kind,
            $absolute,
            trim($description),
            $date,
            $reference,
            $transactionType,
            $memberId,
        );
    }

    private function createOperational(
        BankStatement $statement,
        float $amount,
        string $description,
        string $date,
        ?string $reference,
        ?int $memberId,
    ): BankTransaction {
        if ($description === '') {
            throw new InvalidArgumentException(__('Description is required.'));
        }

        $canonical = $this->syntheticStatements->forFilename($statement->filename);

        if ($canonical->isNot($statement)) {
            throw new InvalidArgumentException(__('Add lines on the system operational statement for this bucket.'));
        }

        $line = match ($statement->filename) {
            BankStatementBuckets::MEMBER_POSTINGS => $this->createMemberPostingLine(
                $memberId,
                $amount,
                $date,
                $reference,
                $description,
            ),
            BankStatementBuckets::MEMBER_CASH_OUTS => $this->createMemberCashOutLine(
                $memberId,
                $amount,
                $date,
                $description,
            ),
            BankStatementBuckets::MASTER_EXPENSE_DISBURSEMENTS => $this->createMasterDisbursementLine(
                Account::masterExpense(),
                fn (Account $account): BankTransaction => $this->expenseDisbursements
                    ->disburse($account, $amount, $description, Carbon::parse($date))
                    ->fresh(['bankTransaction'])
                    ->bankTransaction,
            ),
            BankStatementBuckets::MASTER_FEE_DISBURSEMENTS => $this->createMasterDisbursementLine(
                Account::masterFees(),
                fn (Account $account): BankTransaction => $this->feeDisbursements
                    ->disburse($account, $amount, $description, Carbon::parse($date))
                    ->fresh(['bankTransaction'])
                    ->bankTransaction,
            ),
            BankStatementBuckets::MASTER_INVEST_DISBURSEMENTS => $this->createMasterDisbursementLine(
                Account::masterInvest(),
                fn (Account $account): BankTransaction => $this->investDisbursements
                    ->disburse($account, $amount, $description, Carbon::parse($date))
                    ->fresh(['bankTransaction'])
                    ->bankTransaction,
            ),
            BankStatementBuckets::MASTER_INVEST_RETURNS => $this->createMasterDisbursementLine(
                Account::masterInvest(),
                fn (Account $account): BankTransaction => $this->investReturns
                    ->record($account, $amount, $description, Carbon::parse($date))
                    ->fresh(['bankTransaction'])
                    ->bankTransaction,
            ),
            default => throw new InvalidArgumentException(__('Unsupported operational statement bucket.')),
        };

        if ($line === null) {
            throw new InvalidArgumentException(__('Could not create the operational bank line.'));
        }

        return $line;
    }

    private function createMemberPostingLine(
        ?int $memberId,
        float $amount,
        string $date,
        ?string $reference,
        string $description,
    ): BankTransaction {
        $member = $this->requireMember($memberId);

        $posting = $this->fundPostings->submit(
            $member,
            $amount,
            $date,
            $reference,
            null,
            $description,
        );

        $this->fundPostings->accept(
            $posting->fresh(),
            Auth::guard('tenant')->id(),
            __('Manual operational line'),
        );

        $line = $posting->fresh(['bankTransaction'])->bankTransaction;

        if ($line === null) {
            throw new InvalidArgumentException(__('Could not create the operational bank line.'));
        }

        return $line;
    }

    private function createMemberCashOutLine(
        ?int $memberId,
        float $amount,
        string $date,
        string $description,
    ): BankTransaction {
        $member = $this->requireMember($memberId);

        return MemberCashOutService::withoutNotifications(function () use ($member, $amount, $date, $description): BankTransaction {
            $request = $this->cashOuts->submit($member, $amount, $description);

            $this->cashOuts->accept(
                $request->fresh(),
                Auth::guard('tenant')->id(),
                __('Manual operational line'),
                Carbon::parse($date),
            );

            $line = $request->fresh(['bankTransaction'])->bankTransaction;

            if ($line === null) {
                throw new InvalidArgumentException(__('Could not create the operational bank line.'));
            }

            return $line;
        });
    }

    /**
     * @param  callable(Account): BankTransaction  $create
     */
    private function createMasterDisbursementLine(?Account $account, callable $create): BankTransaction
    {
        if ($account === null) {
            throw new InvalidArgumentException(__('Required master account is not configured.'));
        }

        return $create($account);
    }

    private function requireMember(?int $memberId): Member
    {
        if ($memberId === null) {
            throw new InvalidArgumentException(__('Choose a member for this operational line.'));
        }

        $member = Member::query()->find($memberId);

        if ($member === null) {
            throw new InvalidArgumentException(__('Choose a member for this operational line.'));
        }

        return $member;
    }

    private function createImportedLine(
        BankStatement $statement,
        string $kind,
        float $absolute,
        string $description,
        string $date,
        ?string $reference,
        ?string $transactionType,
        ?int $memberId,
    ): BankTransaction {
        if (! array_key_exists($kind, self::kindOptions())) {
            throw new InvalidArgumentException(__('Choose a credit or debit line.'));
        }

        if ($description === '') {
            throw new InvalidArgumentException(__('Description is required.'));
        }

        $signedAmount = $kind === self::KIND_DEBIT ? -$absolute : $absolute;
        $typeLabel = null;

        if (filled($transactionType) && array_key_exists($transactionType, self::typeOptions())) {
            $typeLabel = self::typeOptions()[$transactionType];
        }

        return DB::transaction(function () use ($statement, $signedAmount, $description, $date, $reference, $typeLabel, $memberId, $kind, $transactionType): BankTransaction {
            $line = BankTransaction::query()->create([
                'bank_statement_id' => $statement->id,
                'transaction_date' => $date,
                'description' => $description,
                'amount' => $signedAmount,
                'reference' => $reference,
                'transaction_type' => $typeLabel,
                'status' => 'imported',
                'member_id' => $memberId,
                'hash' => md5(implode('|', [
                    'manual',
                    $statement->id,
                    $date,
                    $signedAmount,
                    $description,
                    $reference ?? '',
                    $memberId ?? '',
                    microtime(true),
                ])),
                'raw_data' => json_encode([
                    'source' => 'manual',
                    'kind' => $kind,
                    'type_key' => $transactionType,
                    'created_at_business' => BusinessDay::now()->toIso8601String(),
                ]),
                'is_cleared' => false,
                'cleared_at' => null,
            ]);

            $statement->refreshRowCounts();

            return $line;
        });
    }
}
