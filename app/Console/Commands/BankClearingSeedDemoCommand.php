<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant\Account;
use App\Models\Tenant\Member;
use App\Services\AccountingService;
use App\Services\FundPostingService;
use App\Services\MasterExpenseDisbursementService;
use App\Services\MemberCashOutService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Stancl\Tenancy\Concerns\HasATenantsOption;
use Stancl\Tenancy\Concerns\TenantAwareCommand;

class BankClearingSeedDemoCommand extends Command
{
    use HasATenantsOption;
    use TenantAwareCommand;

    protected $signature = 'bank-clearing:seed-demo
        {--fresh : Remove prior demo members (DEMO-BC-*) before seeding}';

    protected $description = 'Seed operational bank-clearing rows that pair with storage/samples/bank-clearing-demo.csv';

    public function handle(
        AccountingService $accounting,
        FundPostingService $fundPostings,
        MemberCashOutService $cashOuts,
        MasterExpenseDisbursementService $expenseDisbursements,
    ): int {
        /** @var array{members: list<array{code: string, name: string, number: string}>, operations: list<array<string, mixed>>} $manifest */
        $manifest = require base_path('storage/samples/bank-clearing-demo-manifest.php');

        if ($this->option('fresh')) {
            $this->removePriorDemoMembers();
        }

        $this->ensureMasterAccounts();

        $membersByCode = $this->seedMembers($manifest['members'], $accounting);

        $masterExpense = Account::query()
            ->where('is_master', true)
            ->where('type', 'expense')
            ->first();

        if ($masterExpense === null) {
            $masterExpense = Account::factory()->masterExpense()->withBalance(50_000)->create();
        } elseif ((float) $masterExpense->balance < 10_000) {
            $masterExpense->update(['balance' => 50_000]);
        }

        $seeded = 0;
        $skipped = 0;

        foreach ($manifest['operations'] as $operation) {
            try {
                $this->seedOperation(
                    $operation,
                    $membersByCode,
                    $accounting,
                    $fundPostings,
                    $cashOuts,
                    $expenseDisbursements,
                    $masterExpense,
                );
                $seeded++;
            } catch (InvalidArgumentException $exception) {
                $this->warn($operation['reference'].': '.$exception->getMessage());
                $skipped++;
            }
        }

        $csvPath = base_path('storage/samples/bank-clearing-demo.csv');

        $this->newLine();
        $this->info(__('Seeded :count operational row(s). Skipped :skipped.', [
            'count' => $seeded,
            'skipped' => $skipped,
        ]));
        $this->line(__('Import this file from Bank clearing → Import:'));
        $this->line($csvPath);
        $this->newLine();
        $this->table(
            ['Scenario', 'Bank refs', 'How to exercise'],
            [
                ['1:1 auto-match', 'BC-AUTO-01 … BC-AUTO-06', __('Auto-match row or bulk Auto-match')],
                ['Ambiguous 1:1', 'BC-AMB-01A/B, BC-AMB-02A/B', __('Manual Match — auto-match skips')],
                ['1→N group', 'BC-1M-01A/B/C, BC-1M-02A/B', __('Match to multiple on operations row')],
                ['N→1 group', 'BC-M1-01, BC-M1-02', __('Match to multiple on bank file row')],
                ['Post as…', 'BC-POST-01 … BC-POST-03', __('No operational row — Post as… on bank file')],
                ['Ignore', 'BC-IGN-01, BC-IGN-02', __('Ignore on bank file row')],
                ['Date window', 'BC-DATE-01, BC-DATE-02', __('Auto vs manual date tolerance (Settings → Reconciliation)')],
                ['Open / unmatched', 'BC-OPEN-*', __('Post as…, ignore, or leave open')],
                ['Manual only', 'BC-MAN-01 vs BC-MAN-02', __('Two bank lines same amount — pick correct Match')],
            ],
        );

        return self::SUCCESS;
    }

    /**
     * @param  list<array{code: string, name: string, number: string}>  $memberDefs
     * @return array<string, Member>
     */
    private function seedMembers(array $memberDefs, AccountingService $accounting): array
    {
        $membersByCode = [];

        foreach ($memberDefs as $def) {
            $member = Member::query()->firstOrCreate(
                ['member_number' => $def['number']],
                [
                    'name' => $def['name'],
                    'monthly_contribution_amount' => 1000,
                    'joined_at' => now()->subYear(),
                    'status' => 'active',
                ],
            );

            if ($member->cashAccount === null || $member->fundAccount === null) {
                $accounting->createMemberAccounts($member);
            }

            $membersByCode[$def['code']] = $member->fresh();
        }

        return $membersByCode;
    }

    /**
     * @param  array<string, mixed>  $operation
     * @param  array<string, Member>  $membersByCode
     */
    private function seedOperation(
        array $operation,
        array $membersByCode,
        AccountingService $accounting,
        FundPostingService $fundPostings,
        MemberCashOutService $cashOuts,
        MasterExpenseDisbursementService $expenseDisbursements,
        Account $masterExpense,
    ): void {
        $reference = (string) $operation['reference'];
        $amount = (float) $operation['amount'];
        $date = (string) $operation['date'];
        $type = (string) $operation['type'];

        if ($this->operationAlreadySeeded($reference)) {
            throw new InvalidArgumentException(__('Already seeded — use --fresh to reset demo members.'));
        }

        match ($type) {
            'deposit' => $this->seedDeposit(
                $operation,
                $membersByCode,
                $fundPostings,
            ),
            'cash_out' => $this->seedCashOut(
                $operation,
                $membersByCode,
                $accounting,
                $cashOuts,
            ),
            'expense' => $this->seedExpense(
                $operation,
                $expenseDisbursements,
                $masterExpense,
            ),
            default => throw new InvalidArgumentException(__('Unknown operation type :type.', ['type' => $type])),
        };
    }

    /**
     * @param  array<string, mixed>  $operation
     * @param  array<string, Member>  $membersByCode
     */
    private function seedDeposit(array $operation, array $membersByCode, FundPostingService $fundPostings): void
    {
        $memberCode = (string) ($operation['member_code'] ?? '');
        $member = $membersByCode[$memberCode] ?? null;

        if ($member === null) {
            throw new InvalidArgumentException(__('Member :code not found.', ['code' => $memberCode]));
        }

        $posting = $fundPostings->submit(
            $member,
            (float) $operation['amount'],
            (string) $operation['date'],
        );
        $fundPostings->accept($posting);

        $bankTxn = $posting->fresh()->bankTransaction;

        if ($bankTxn !== null) {
            $bankTxn->update([
                'reference' => (string) $operation['reference'],
                'description' => (string) ($operation['description'] ?? $bankTxn->description),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $operation
     * @param  array<string, Member>  $membersByCode
     */
    private function seedCashOut(
        array $operation,
        array $membersByCode,
        AccountingService $accounting,
        MemberCashOutService $cashOuts,
    ): void {
        $memberCode = (string) ($operation['member_code'] ?? '');
        $member = $membersByCode[$memberCode] ?? null;

        if ($member === null) {
            throw new InvalidArgumentException(__('Member :code not found.', ['code' => $memberCode]));
        }

        $amount = (float) $operation['amount'];
        $member->refresh();

        if ((float) $member->getCashBalance() < $amount + 500) {
            AccountingService::withoutMemberCashCollection(function () use ($accounting, $member, $amount): void {
                $accounting->creditMemberCashWithMasterMirror(
                    $member->cashAccount,
                    $amount + 5_000,
                    __('Demo seed cash for bank clearing'),
                    __('(demo seed)'),
                    null,
                    null,
                    $member->id,
                );
            });
            $member->refresh();
        }

        $request = $cashOuts->submit(
            $member,
            $amount,
            (string) ($operation['description'] ?? __('Demo cash-out')),
            bypassAvailabilityGuard: true,
        );
        $cashOuts->accept($request, reviewedBy: null, bypassAvailabilityGuard: true);

        $bankTxn = $request->fresh()->bankTransaction;

        if ($bankTxn !== null) {
            $bankTxn->update([
                'reference' => (string) $operation['reference'],
                'description' => (string) ($operation['description'] ?? $bankTxn->description),
            ]);
        }
    }

    private function seedExpense(
        array $operation,
        MasterExpenseDisbursementService $expenseDisbursements,
        Account $masterExpense,
    ): void {
        $disbursement = $expenseDisbursements->disburse(
            $masterExpense,
            (float) $operation['amount'],
            (string) ($operation['description'] ?? __('Demo expense :ref', ['ref' => $operation['reference']])),
            Carbon::parse((string) $operation['date']),
        );

        $bankTxn = $disbursement->fresh()->bankTransaction;

        if ($bankTxn !== null) {
            $bankTxn->update(['reference' => (string) $operation['reference']]);
        }
    }

    private function operationAlreadySeeded(string $reference): bool
    {
        return DB::table('bank_transactions')
            ->where('reference', $reference)
            ->exists();
    }

    private function ensureMasterAccounts(): void
    {
        foreach (['cash', 'fund', 'bank'] as $type) {
            Account::query()->firstOrCreate(
                ['type' => $type, 'is_master' => true],
                ['name' => 'Master '.ucfirst($type), 'balance' => 0],
            );
        }
    }

    private function removePriorDemoMembers(): void
    {
        $manifest = require base_path('storage/samples/bank-clearing-demo-manifest.php');
        $numbers = array_column($manifest['members'] ?? [], 'number');

        if ($numbers === []) {
            return;
        }

        Member::query()->whereIn('member_number', $numbers)->delete();
        $this->info(__('Removed prior demo members.'));
    }
}
