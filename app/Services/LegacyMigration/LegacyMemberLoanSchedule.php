<?php

declare(strict_types=1);

namespace App\Services\LegacyMigration;

use App\Models\Tenant\Loan;
use App\Models\Tenant\Member;

/**
 * Loads a member's loans in disbursement order for legacy payment chronology.
 */
final class LegacyMemberLoanSchedule
{
    public function forMember(
        LegacyPaymentClassifyMember $member,
        ?LegacyMigrationCsvLoanIndex $loanIndex = null,
    ): array {
        $csvWindows = $loanIndex !== null
            && ! $loanIndex->isEmpty()
            && $loanIndex->hasMember($member->memberNumber)
            ? $this->windowsFromCsv($member->memberNumber, $loanIndex)
            : [];

        if ($member->databaseMember !== null) {
            $windows = $this->windowsFromDatabase($member->databaseMember);

            if ($windows !== []) {
                return $this->mergeDatabaseWindowsWithCsvLegacyIds($windows, $csvWindows);
            }
        }

        return $csvWindows;
    }

    /**
     * @param  list<LegacyMemberLoanWindow>  $databaseWindows
     * @param  list<LegacyMemberLoanWindow>  $csvWindows
     * @return list<LegacyMemberLoanWindow>
     */
    private function mergeDatabaseWindowsWithCsvLegacyIds(array $databaseWindows, array $csvWindows): array
    {
        if ($csvWindows === []) {
            return $databaseWindows;
        }

        return array_map(function (LegacyMemberLoanWindow $window) use ($csvWindows): LegacyMemberLoanWindow {
            foreach ($csvWindows as $csvWindow) {
                if ($csvWindow->disbursedAt->toDateString() !== $window->disbursedAt->toDateString()) {
                    continue;
                }

                if ($csvWindow->loanId === null) {
                    break;
                }

                return new LegacyMemberLoanWindow(
                    loanKey: $window->loanKey,
                    disbursedAt: $window->disbursedAt,
                    fundPortionTarget: $window->fundPortionTarget,
                    loanId: $csvWindow->loanId,
                );
            }

            return $window;
        }, $databaseWindows);
    }

    /**
     * @return list<LegacyMemberLoanWindow>
     */
    private function windowsFromDatabase(Member $member): array
    {
        return $member->loans()
            ->whereIn('status', ['active', 'transferred', 'completed', 'early_settled'])
            ->whereNotNull('disbursed_at')
            ->orderBy('disbursed_at')
            ->orderBy('id')
            ->get()
            ->map(fn (Loan $loan): LegacyMemberLoanWindow => LegacyMemberLoanWindow::fromLoan(
                $loan,
                (string) $member->member_number,
            ))
            ->values()
            ->all();
    }

    /**
     * @return list<LegacyMemberLoanWindow>
     */
    private function windowsFromCsv(string $memberNumber, LegacyMigrationCsvLoanIndex $loanIndex): array
    {
        return $loanIndex->loanWindowsForMember($memberNumber);
    }
}
