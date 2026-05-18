<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Filament\Tenant\Resources\Members\MemberResource;
use App\Models\Tenant\Member;
use Filament\Tables\Columns\TextColumn;

final class MemberTableColumns
{
    public static function number(
        string $column = 'member_number',
        ?string $label = null,
    ): TextColumn {
        $textColumn = TextColumn::make($column);

        if ($label !== null) {
            $textColumn->label($label);
        }

        return $textColumn->url(self::memberRecordEditUrl(...));
    }

    public static function name(
        string $column = 'name',
        ?string $label = null,
    ): TextColumn {
        $textColumn = TextColumn::make($column);

        if ($label !== null) {
            $textColumn->label($label);
        }

        return $textColumn->url(self::memberRecordEditUrl(...));
    }

    public static function relationNumber(?string $label = null): TextColumn
    {
        return self::number('member.member_number', $label ?? __('Member #'))
            ->url(self::relatedMemberEditUrl(...));
    }

    public static function relationName(?string $label = null): TextColumn
    {
        return self::name('member.name', $label)
            ->url(self::relatedMemberEditUrl(...));
    }

    public static function memberRecordEditUrl(Member $record): string
    {
        return MemberResource::getUrl('edit', ['record' => $record]);
    }

    public static function relatedMemberEditUrl(object $record): ?string
    {
        $member = $record->member ?? null;

        if (! $member instanceof Member) {
            return null;
        }

        return self::memberRecordEditUrl($member);
    }

    /**
     * @param  array{member_id: int|string}  $record
     */
    public static function memberIdEditUrl(array $record): string
    {
        return MemberResource::getUrl('edit', ['record' => $record['member_id']]);
    }
}
