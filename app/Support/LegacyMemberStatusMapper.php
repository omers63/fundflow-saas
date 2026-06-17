<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant\Member;

/**
 * Maps legacy / localized member status labels from CSV imports to canonical values.
 */
final class LegacyMemberStatusMapper
{
    /**
     * @var array<string, string>
     */
    private const ALIASES = [
        // Arabic (legacy samman export)
        'مستمر' => 'active',
        'منسحب' => 'withdrawn',
        'معلق' => 'suspended',
        'متأخر' => 'delinquent',
        'منتهي' => 'terminated',
        // English synonyms
        'continuing' => 'active',
        'ongoing' => 'active',
        'inactive' => 'withdrawn',
        'resigned' => 'withdrawn',
    ];

    public static function normalize(string $value): string
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return 'active';
        }

        if (isset(self::ALIASES[$trimmed])) {
            return self::ALIASES[$trimmed];
        }

        $lower = strtolower($trimmed);

        if (isset(self::ALIASES[$lower])) {
            return self::ALIASES[$lower];
        }

        if (array_key_exists($lower, Member::statusOptions())) {
            return $lower;
        }

        throw new \InvalidArgumentException(
            __('status must be one of: :statuses.', ['statuses' => implode(', ', Member::STATUSES)])
        );
    }
}
