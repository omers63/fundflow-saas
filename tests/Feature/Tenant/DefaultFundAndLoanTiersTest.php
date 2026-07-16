<?php

declare(strict_types=1);

use App\Models\Tenant\FundTier;
use App\Models\Tenant\LoanTier;
use App\Support\DefaultFundAndLoanTiers;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
});

test('default fund and loan tier definitions match the samman production shape', function () {
    $loanTiers = DefaultFundAndLoanTiers::loanTiers();
    $fundTiers = DefaultFundAndLoanTiers::fundTiers();

    expect($loanTiers)->toHaveCount(11)
        ->and($fundTiers)->toHaveCount(6)
        ->and($loanTiers[0]['label'])->toBe('1K->5K - 500')
        ->and($loanTiers[0]['min_amount'])->toBe(0)
        ->and($loanTiers[0]['max_amount'])->toBe(5_000)
        ->and($loanTiers[10]['label'])->toBe('271K->300K - 5.5K')
        ->and($fundTiers[0])->toMatchArray([
            'tier_number' => 0,
            'label' => 'Emergency',
            'percentage' => 100,
            'loan_tier_numbers' => [],
        ])
        ->and(collect($fundTiers)->where('tier_number', '>', 0)->pluck('percentage')->all())
        ->toBe([40, 30, 10, 10, 10])
        ->and(collect($fundTiers)->where('tier_number', '>', 0)->pluck('loan_tier_numbers')->all())
        ->toBe([[0, 1], [2, 3], [4, 5], [6, 7], [8, 9, 10]]);
});

test('seedIfEmpty inserts linked defaults only when both tier tables are empty', function () {
    FundTier::query()->forceDelete();
    LoanTier::query()->forceDelete();

    DefaultFundAndLoanTiers::seedIfEmpty();

    expect(LoanTier::query()->count())->toBe(11)
        ->and(FundTier::query()->count())->toBe(6);

    $emergency = FundTier::query()->where('tier_number', 0)->first();
    $tier1 = FundTier::query()->where('tier_number', 1)->with('loanTiers')->first();
    $tier5 = FundTier::query()->where('tier_number', 5)->with('loanTiers')->first();
    $band0 = LoanTier::query()->where('tier_number', 0)->first();
    $band1 = LoanTier::query()->where('tier_number', 1)->first();

    expect($emergency)->not->toBeNull()
        ->and($emergency->label)->toBe('Emergency')
        ->and((float) $emergency->percentage)->toBe(100.0)
        ->and($emergency->loanTiers)->toHaveCount(0)
        ->and($tier1->label)->toBe('Tier 1')
        ->and((float) $tier1->percentage)->toBe(40.0)
        ->and($tier1->loanTiers->pluck('tier_number')->sort()->values()->all())->toBe([0, 1])
        ->and($band0->fund_tier_id)->toBe($tier1->id)
        ->and($band1->fund_tier_id)->toBe($tier1->id)
        ->and($tier5->loanTiers->pluck('tier_number')->sort()->values()->all())->toBe([8, 9, 10]);

    foreach (DefaultFundAndLoanTiers::fundTiers() as $definition) {
        $fund = FundTier::query()->where('tier_number', $definition['tier_number'])->first();

        expect($fund)->not->toBeNull()
            ->and((float) $fund->percentage)->toBe((float) $definition['percentage']);

        foreach ($definition['loan_tier_numbers'] as $loanTierNumber) {
            $loan = LoanTier::query()->where('tier_number', $loanTierNumber)->first();

            expect($loan->fund_tier_id)->toBe($fund->id);
        }
    }

    DefaultFundAndLoanTiers::seedIfEmpty();

    expect(LoanTier::query()->count())->toBe(11)
        ->and(FundTier::query()->count())->toBe(6);
});
