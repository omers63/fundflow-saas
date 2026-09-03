<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant\Setting;
use InvalidArgumentException;

/**
 * Tenant evidence channel for treasury clearance (bank CSV vs SMS alerts).
 */
final class EvidenceChannelSettings
{
    public const GROUP = 'reconciliation';

    public const KEY = 'evidence_channel';

    public const CHANNEL_BANK_CSV = 'bank_csv';

    public const CHANNEL_SMS = 'sms';

    public const CHANNEL_BOTH = 'both';

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::CHANNEL_BANK_CSV => __('Bank statement CSV'),
            self::CHANNEL_SMS => __('SMS alerts only'),
            self::CHANNEL_BOTH => __('Both (bank CSV + SMS)'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function allForForm(): array
    {
        return [
            'reconciliation_evidence_channel' => self::channel(),
        ];
    }

    public static function channel(): string
    {
        $value = Setting::get(self::GROUP, self::KEY, self::CHANNEL_BANK_CSV);

        return self::normalize(is_string($value) ? $value : self::CHANNEL_BANK_CSV);
    }

    public static function usesBankCsv(): bool
    {
        return in_array(self::channel(), [self::CHANNEL_BANK_CSV, self::CHANNEL_BOTH], true);
    }

    public static function usesSms(): bool
    {
        return in_array(self::channel(), [self::CHANNEL_SMS, self::CHANNEL_BOTH], true);
    }

    public static function isSmsOnly(): bool
    {
        return self::channel() === self::CHANNEL_SMS;
    }

    public static function isBankCsvOnly(): bool
    {
        return self::channel() === self::CHANNEL_BANK_CSV;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public static function saveFromForm(array $state): void
    {
        Setting::set(
            self::GROUP,
            self::KEY,
            self::normalize((string) ($state['reconciliation_evidence_channel'] ?? self::CHANNEL_BANK_CSV)),
        );
    }

    public static function save(string $channel): void
    {
        Setting::set(self::GROUP, self::KEY, self::normalize($channel));
    }

    private static function normalize(string $channel): string
    {
        return match ($channel) {
            self::CHANNEL_BANK_CSV, self::CHANNEL_SMS, self::CHANNEL_BOTH => $channel,
            default => throw new InvalidArgumentException(__('Invalid evidence channel.')),
        };
    }
}
