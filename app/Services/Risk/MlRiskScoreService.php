<?php

declare(strict_types=1);

namespace App\Services\Risk;

use App\Models\Tenant\Member;
use App\Models\Tenant\MemberRiskOutcome;
use App\Support\RiskSettings;

/**
 * Lightweight supervised blend: logistic over labeled outcomes + rules score.
 * Falls back to rules-only when ML is disabled or too few labels exist.
 */
final class MlRiskScoreService
{
    public function __construct(
        private readonly MemberRiskScoreService $rules,
    ) {
    }

    /**
     * @return array{
     *     score: int,
     *     band: string,
     *     factors: list<array{code: string, label: string, points: int}>,
     *     member_id: int,
     *     source: string,
     *     ml_probability: ?float
     * }
     */
    public function score(Member $member): array
    {
        $base = $this->rules->score($member);

        if (!RiskSettings::mlEnabled()) {
            return $base + ['source' => 'rules', 'ml_probability' => null];
        }

        $labels = MemberRiskOutcome::query()->latest('id')->limit(500)->get();
        if ($labels->count() < RiskSettings::mlMinLabels()) {
            return $base + ['source' => 'rules_insufficient_labels', 'ml_probability' => null];
        }

        $defaultRate = max(0.01, min(0.99, $labels->where('defaulted', true)->count() / max(1, $labels->count())));
        $avgRules = (float) $labels->avg('rules_score');
        $weight = ($defaultRate - 0.5) * 2; // -1..1
        $delta = (($base['score'] - $avgRules) / 100) * $weight;
        $probability = 1 / (1 + exp(-(($base['score'] / 100) * 3 + $delta - 1.2)));

        $mlScore = (int) round(min(100, max(0, $probability * 100)));
        $blended = (int) round(($base['score'] * (1 - RiskSettings::mlBlendWeight())) + ($mlScore * RiskSettings::mlBlendWeight()));

        $factors = $base['factors'];
        $factors[] = [
            'code' => 'ml_blend',
            'label' => __('ML blend (p=:p)', ['p' => number_format($probability, 2)]),
            'points' => $blended - $base['score'],
        ];

        $band = $this->bandFor($blended);

        return [
            'score' => $blended,
            'band' => $band,
            'factors' => $factors,
            'member_id' => $member->id,
            'source' => 'ml_blend',
            'ml_probability' => round($probability, 4),
        ];
    }

    public function recordOutcome(Member $member, bool $defaulted, string $source = 'manual'): MemberRiskOutcome
    {
        $rules = $this->rules->score($member);

        return MemberRiskOutcome::query()->create([
            'member_id' => $member->id,
            'rules_score' => $rules['score'],
            'rules_band' => $rules['band'],
            'defaulted' => $defaulted,
            'label_source' => $source,
            'features' => ['factors' => $rules['factors']],
            'labeled_at' => now(),
        ]);
    }

    public function bandColor(string $band): string
    {
        return $this->rules->bandColor($band);
    }

    /**
     * @return list<array{member: Member, score: array{score: int, band: string, factors: list<array{code: string, label: string, points: int}>, member_id: int, source?: string, ml_probability?: ?float}}>
     */
    public function watchlist(string $minBand = 'high'): array
    {
        $bands = match ($minBand) {
            'critical' => ['critical'],
            'medium' => ['medium', 'high', 'critical'],
            default => ['high', 'critical'],
        };

        $rows = [];

        Member::query()
            ->where('status', 'active')
            ->orderBy('id')
            ->each(function (Member $member) use (&$rows, $bands): void {
                $score = $this->score($member);

                if (in_array($score['band'], $bands, true)) {
                    $rows[] = ['member' => $member, 'score' => $score];
                }
            });

        usort($rows, fn(array $a, array $b): int => $b['score']['score'] <=> $a['score']['score']);

        return $rows;
    }

    private function bandFor(int $score): string
    {
        if ($score >= RiskSettings::criticalScoreThreshold()) {
            return 'critical';
        }
        if ($score >= RiskSettings::highScoreThreshold()) {
            return 'high';
        }
        if ($score >= 40) {
            return 'medium';
        }

        return 'low';
    }
}
