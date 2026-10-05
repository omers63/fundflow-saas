<?php

declare(strict_types=1);

namespace App\Services\ProfitDistribution;

use App\Models\Tenant\Member;
use App\Models\Tenant\ProfitDistribution;
use App\Models\Tenant\ProfitDistributionItem;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\Governance\MotionEnforcementGate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ProfitDistributionService
{
    public function __construct(
        private readonly AccountingService $accounting,
        private readonly MotionEnforcementGate $motions,
    ) {}

    public function createDraft(
        string $periodStart,
        string $periodEnd,
        float $amount,
        ?User $creator = null,
        ?int $motionId = null,
        ?string $notes = null,
    ): ProfitDistribution {
        $amount = round($amount, 2);

        if ($amount <= 0) {
            throw new InvalidArgumentException(__('Distribution amount must be greater than zero.'));
        }

        if ($periodEnd < $periodStart) {
            throw new InvalidArgumentException(__('Period end must be on or after period start.'));
        }

        $distribution = ProfitDistribution::query()->create([
            'status' => ProfitDistribution::STATUS_DRAFT,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'amount' => $amount,
            'allocation_method' => ProfitDistribution::METHOD_MONTH_END_FUND,
            'motion_id' => $motionId,
            'created_by' => $creator?->id,
            'notes' => $notes,
        ]);

        $this->rebuildPreview($distribution);

        return $distribution->fresh(['items']) ?? $distribution;
    }

    public function rebuildPreview(ProfitDistribution $distribution): ProfitDistribution
    {
        if ($distribution->status !== ProfitDistribution::STATUS_DRAFT) {
            throw new InvalidArgumentException(__('Only draft distributions can be previewed.'));
        }

        $weights = $this->memberWeights();

        if ($weights->isEmpty()) {
            throw new InvalidArgumentException(__('No eligible members with fund balances found.'));
        }

        $totalWeight = (float) $weights->sum('weight');

        if ($totalWeight <= 0.0000001) {
            throw new InvalidArgumentException(__('Total allocation weight is zero.'));
        }

        $amount = (float) $distribution->amount;

        return DB::transaction(function () use ($distribution, $weights, $totalWeight, $amount): ProfitDistribution {
            $distribution->items()->delete();

            $allocated = 0.0;
            $rows = $weights->values();
            $lastIndex = $rows->count() - 1;

            foreach ($rows as $index => $row) {
                if ($index === $lastIndex) {
                    $share = round($amount - $allocated, 2);
                } else {
                    $share = round($amount * ((float) $row['weight'] / $totalWeight), 2);
                    $allocated += $share;
                }

                if ($share <= 0) {
                    continue;
                }

                ProfitDistributionItem::query()->create([
                    'profit_distribution_id' => $distribution->id,
                    'member_id' => $row['member_id'],
                    'weight' => $row['weight'],
                    'amount' => $share,
                    'status' => ProfitDistributionItem::STATUS_PREVIEW,
                ]);
            }

            $distribution->forceFill([
                'item_count' => $distribution->items()->count(),
                'posted_total' => 0,
            ])->save();

            return $distribution->fresh(['items']) ?? $distribution;
        });
    }

    public function approve(ProfitDistribution $distribution, User $approver): ProfitDistribution
    {
        if ($distribution->status !== ProfitDistribution::STATUS_DRAFT) {
            throw new InvalidArgumentException(__('Only draft distributions can be approved.'));
        }

        if ($distribution->items()->count() === 0) {
            throw new InvalidArgumentException(__('Rebuild the preview before approving.'));
        }

        $this->motions->assertDistributionAllowed((float) $distribution->amount, $distribution->motion_id);

        $distribution->forceFill([
            'status' => ProfitDistribution::STATUS_APPROVED,
            'approved_by' => $approver->id,
            'approved_at' => now(),
        ])->save();

        return $distribution->fresh() ?? $distribution;
    }

    public function post(ProfitDistribution $distribution): ProfitDistribution
    {
        if ($distribution->status !== ProfitDistribution::STATUS_APPROVED) {
            throw new InvalidArgumentException(__('Approve the distribution before posting.'));
        }

        $this->motions->assertDistributionAllowed((float) $distribution->amount, $distribution->motion_id);

        return DB::transaction(function () use ($distribution): ProfitDistribution {
            $distribution->load('items.member.fundAccount');
            $posted = 0.0;

            foreach ($distribution->items as $item) {
                $member = $item->member;
                $fund = $member?->fundAccount;

                if ($fund === null) {
                    throw new InvalidArgumentException(__('Member #:id has no fund account.', [
                        'id' => $item->member_id,
                    ]));
                }

                $share = (float) $item->amount;
                $description = __('Profit distribution #:id — :name', [
                    'id' => $distribution->id,
                    'name' => $member->name,
                ]);

                $this->accounting->creditMemberFundWithMasterMirror(
                    $fund,
                    $share,
                    $description,
                    __('(profit distribution mirror)'),
                    $distribution,
                    null,
                    $member->id,
                );

                $item->forceFill(['status' => ProfitDistributionItem::STATUS_POSTED])->save();
                $posted += $share;
            }

            $distribution->forceFill([
                'status' => ProfitDistribution::STATUS_POSTED,
                'posted_at' => now(),
                'posted_total' => round($posted, 2),
            ])->save();

            return $distribution->fresh(['items']) ?? $distribution;
        });
    }

    public function reverse(ProfitDistribution $distribution): ProfitDistribution
    {
        if ($distribution->status !== ProfitDistribution::STATUS_POSTED) {
            throw new InvalidArgumentException(__('Only posted distributions can be reversed.'));
        }

        return DB::transaction(function () use ($distribution): ProfitDistribution {
            $distribution->load('items.member.fundAccount');

            foreach ($distribution->items as $item) {
                if ($item->status !== ProfitDistributionItem::STATUS_POSTED) {
                    continue;
                }

                $member = $item->member;
                $fund = $member?->fundAccount;

                if ($fund === null) {
                    throw new InvalidArgumentException(__('Member #:id has no fund account.', [
                        'id' => $item->member_id,
                    ]));
                }

                $share = (float) $item->amount;
                $description = __('Reversal of profit distribution #:id — :name', [
                    'id' => $distribution->id,
                    'name' => $member->name,
                ]);

                $this->accounting->debitMemberFundWithMasterMirror(
                    $fund,
                    $share,
                    $description,
                    __('(profit distribution reversal mirror)'),
                    $distribution,
                    null,
                    $member->id,
                );

                $item->forceFill(['status' => ProfitDistributionItem::STATUS_REVERSED])->save();
            }

            $distribution->forceFill([
                'status' => ProfitDistribution::STATUS_REVERSED,
                'reversed_at' => now(),
            ])->save();

            return $distribution->fresh(['items']) ?? $distribution;
        });
    }

    public function hasBlockingOpenRuns(): bool
    {
        return ProfitDistribution::query()
            ->whereIn('status', [
                ProfitDistribution::STATUS_DRAFT,
                ProfitDistribution::STATUS_APPROVED,
            ])
            ->exists();
    }

    /**
     * @return Collection<int, array{member_id: int, weight: float}>
     */
    private function memberWeights(): Collection
    {
        return Member::query()
            ->where('status', 'active')
            ->with('fundAccount')
            ->get()
            ->map(function (Member $member): ?array {
                $balance = (float) ($member->fundAccount?->balance ?? 0);

                if ($balance <= 0.00001) {
                    return null;
                }

                return [
                    'member_id' => $member->id,
                    'weight' => round($balance, 6),
                ];
            })
            ->filter()
            ->values();
    }
}
