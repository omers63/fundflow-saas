<?php

declare(strict_types=1);

namespace App\Services;

use App\Filament\Support\LateSettledArrearsTableStyling;
use App\Models\Tenant\Contribution;
use App\Services\Loans\LoanDelinquencyService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ContributionLatePostingClearanceService
{
    public function __construct(
        private readonly LoanDelinquencyService $delinquency,
    ) {}

    /**
     * Remove the late-settled flag from a posted contribution without changing cash or status.
     *
     * @return 'cleared'|'skipped'|'already_clear'
     */
    public function clearContribution(Contribution $contribution, ?string $note = null): string
    {
        if (! $contribution->is_late) {
            return 'already_clear';
        }

        if (! LateSettledArrearsTableStyling::contributionWasSettledLate($contribution)) {
            throw new InvalidArgumentException(__('Only posted contributions marked as late can be cleared.'));
        }

        $suffix = __('Late posting flag cleared by administrator.');
        $combinedNote = trim(implode(' ', array_filter([
            trim((string) ($contribution->notes ?? '')),
            trim((string) ($note ?? '')),
            $suffix,
        ])));

        DB::transaction(function () use ($contribution, $combinedNote): void {
            $contribution->update([
                'is_late' => false,
                'notes' => $combinedNote !== '' ? $combinedNote : null,
            ]);
        });

        $contribution->loadMissing('member');
        if ($contribution->member !== null) {
            $this->delinquency->syncMemberDelinquencyStatusForMember($contribution->member->fresh() ?? $contribution->member);
        }

        return 'cleared';
    }

    /**
     * @param  Collection<int, Contribution>|iterable<int, Contribution>  $contributions
     * @return array{cleared: int, skipped: int, already_clear: int}
     */
    public function clearMany(iterable $contributions, ?string $note = null): array
    {
        $summary = [
            'cleared' => 0,
            'skipped' => 0,
            'already_clear' => 0,
        ];

        foreach ($contributions as $contribution) {
            if (! $contribution instanceof Contribution) {
                $summary['skipped']++;

                continue;
            }

            try {
                $outcome = $this->clearContribution($contribution, $note);
            } catch (InvalidArgumentException) {
                $summary['skipped']++;

                continue;
            }

            $summary[$outcome === 'cleared' ? 'cleared' : ($outcome === 'already_clear' ? 'already_clear' : 'skipped')]++;
        }

        return $summary;
    }
}
