<?php

declare(strict_types=1);

namespace App\Support\Insights;

/**
 * Helpers for insight KPI cards (clickable stat strips).
 */
final class InsightKpi
{
    /**
     * KPI card fields for a monetary value (rendered with symbol before digits).
     *
     * @return array{value: float, currency: string, value_compact: bool, value_is_amount: true}
     */
    public static function moneyValue(float $amount, string $currency, bool $compact = true): array
    {
        return [
            'value' => $amount,
            'currency' => $currency,
            'value_compact' => $compact,
            'value_is_amount' => true,
        ];
    }

    /**
     * KPI card fields for a quantity / count (never rendered as currency).
     *
     * @return array{value: string, value_is_amount: false}
     */
    public static function countValue(int|float|string $count): array
    {
        return [
            'value' => (string) $count,
            'value_is_amount' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $card
     * @return array<string, mixed>
     */
    public static function link(array $card, ?string $url): array
    {
        if (filled($url)) {
            $card['url'] = $url;
        }

        return $card;
    }

    /**
     * @param  list<array<string, mixed>>  $cards
     * @param  array<string, string|null>  $urlsByKey
     * @return list<array<string, mixed>>
     */
    public static function linkMany(array $cards, array $urlsByKey): array
    {
        return array_map(function (array $card) use ($urlsByKey): array {
            $key = (string) ($card['key'] ?? $card['label'] ?? '');

            return self::link($card, $urlsByKey[$key] ?? null);
        }, $cards);
    }
}
