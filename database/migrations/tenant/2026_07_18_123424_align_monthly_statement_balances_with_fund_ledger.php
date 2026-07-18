<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('monthly_statements')
            ->select(['id', 'member_id', 'period', 'generated_at', 'details'])
            ->orderBy('id')
            ->chunkById(200, function ($statements): void {
                foreach ($statements as $statement) {
                    $details = json_decode((string) $statement->details, true);

                    try {
                        $periodStart = Carbon::createFromFormat('!Y-m', (string) $statement->period)->startOfMonth();
                    } catch (Throwable) {
                        continue;
                    }

                    $periodEnd = $periodStart->copy()->endOfMonth();
                    $asOf = filled($details['as_of'] ?? null)
                        ? Carbon::parse($details['as_of'])->endOfDay()
                        : Carbon::parse($statement->generated_at)->endOfDay();
                    $closingCutoff = $periodEnd->min($asOf);

                    DB::table('monthly_statements')
                        ->where('id', $statement->id)
                        ->update([
                            'opening_balance' => $this->fundBalanceAt(
                                (int) $statement->member_id,
                                $periodStart->copy()->subSecond(),
                            ),
                            'closing_balance' => $this->fundBalanceAt(
                                (int) $statement->member_id,
                                $closingCutoff,
                            ),
                        ]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // The prior contribution-minus-repayment roll-forward cannot be restored reliably.
    }

    private function fundBalanceAt(int $memberId, Carbon $cutoff): float
    {
        $accountId = DB::table('accounts')
            ->where('member_id', $memberId)
            ->where('type', 'fund')
            ->value('id');

        if ($accountId === null) {
            return 0.0;
        }

        $totals = DB::table('transactions')
            ->where('account_id', $accountId)
            ->where('transacted_at', '<=', $cutoff)
            ->selectRaw("
                COALESCE(SUM(CASE WHEN type = 'credit' THEN amount ELSE 0 END), 0) as credits,
                COALESCE(SUM(CASE WHEN type = 'debit' THEN amount ELSE 0 END), 0) as debits
            ")
            ->first();

        return round((float) $totals->credits - (float) $totals->debits, 2);
    }
};
