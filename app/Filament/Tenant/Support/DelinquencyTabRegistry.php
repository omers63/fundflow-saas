<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Support;

use App\Filament\Tenant\Pages\DelinquencyWorkspacePage;
use App\Models\Tenant\Member;

final class DelinquencyTabRegistry
{
    public const TABS = ['overview', 'overdue', 'guarantor', 'policy', 'related'];

    /**
     * @return array<string, string>
     */
    public static function tabs(): array
    {
        return [
            'overview' => __('Overview'),
            'overdue' => __('Overdue'),
            'guarantor' => __('Guarantor'),
            'policy' => __('Policy breaches'),
            'related' => __('Related'),
        ];
    }

    public static function normalize(string $sideTab): string
    {
        return in_array($sideTab, self::TABS, true) ? $sideTab : 'overview';
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    public static function url(string $sideTab, array $parameters = []): string
    {
        return DelinquencyWorkspacePage::getUrl([
            'sideTab' => self::normalize($sideTab),
            ...$parameters,
        ]);
    }

    public static function overdueUrlForMember(int|Member $member): string
    {
        $memberId = $member instanceof Member ? (int) $member->getKey() : $member;

        return self::url('overdue', ['memberId' => $memberId]);
    }
}
