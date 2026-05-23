<?php

declare(strict_types=1);

namespace App\Support\Insights;

/**
 * Helpers for insight KPI cards (clickable stat strips).
 */
final class InsightKpi
{
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
