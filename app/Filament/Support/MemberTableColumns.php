<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Filament\Tenant\Resources\Members\MemberResource;
use App\Models\Tenant\Member;
use App\Support\ArabicDisplaySettings;
use App\Support\ArabicTypography;
use App\Support\MemberNumberSettings;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;

final class MemberTableColumns
{
    /**
     * @var list<string>
     */
    private const MEMBER_LINK_COLUMNS = [
        'member.name',
        'member.member_number',
        'member_name',
        'member_number',
        'loan.member.name',
        'loan.member.member_number',
        'loan.guarantor.name',
        'loan.guarantor.member_number',
        'guarantor.name',
        'guarantor.member_number',
        'user.name',
    ];

    public static function shouldLinkColumn(string $columnName): bool
    {
        if (in_array($columnName, self::MEMBER_LINK_COLUMNS, true)) {
            return true;
        }

        return $columnName === 'name';
    }

    public static function resolveMemberUrl(string $columnName, mixed $record): ?string
    {
        if ($record instanceof Member) {
            if (in_array($columnName, ['name', 'user.name', 'member_number'], true)) {
                return self::memberRecordEditUrl($record);
            }
        }

        if (in_array($columnName, ['member_name', 'member_number'], true)) {
            $memberId = self::extractMemberId($record);

            return $memberId !== null
                ? self::memberIdEditUrl(['member_id' => $memberId])
                : null;
        }

        $member = self::resolveMemberModel($columnName, $record);

        return $member instanceof Member ? self::memberRecordEditUrl($member) : null;
    }

    public static function applyMemberLinkToTextColumn(TextColumn $column): TextColumn
    {
        $columnName = $column->getName();

        return $column->url(
            fn (mixed $state, mixed $record): ?string => self::resolveMemberUrl($columnName, $record),
        );
    }

    public static function number(
        string $column = 'member_number',
        ?string $label = null,
    ): TextColumn {
        $textColumn = TextColumn::make($column);

        if ($label !== null) {
            $textColumn->label($label);
        }

        return $textColumn
            ->url(
                fn (mixed $state, Member $record): string => self::memberRecordEditUrl($record),
            )
            ->sortable(query: fn (Builder $query, string $direction): Builder => MemberNumberSettings::applySequenceOrder(
                $query,
                $direction,
                self::qualifiedMemberNumberColumn($column),
            ));
    }

    public static function name(
        string $column = 'name',
        ?string $label = null,
    ): TextColumn {
        $textColumn = TextColumn::make($column);

        if ($label !== null) {
            $textColumn->label($label);
        }

        return self::applyArabicNameTypography($textColumn)
            ->url(fn (mixed $state, Member $record): string => self::memberRecordEditUrl($record));
    }

    public static function relationNumber(?string $label = null): TextColumn
    {
        return self::relationNumberFor(
            memberNumberColumn: 'member.member_number',
            memberIdColumn: null,
            label: $label ?? __('Member #'),
        )->url(fn (mixed $state, mixed $record): ?string => self::relatedMemberEditUrl($record));
    }

    public static function relationNumberFor(
        string $memberNumberColumn = 'member.member_number',
        ?string $memberIdColumn = null,
        ?string $label = null,
    ): TextColumn {
        $textColumn = TextColumn::make($memberNumberColumn);

        if ($label !== null) {
            $textColumn->label($label);
        }

        return $textColumn->sortable(query: function (Builder $query, string $direction) use ($memberIdColumn): Builder {
            return MemberNumberSettings::applyOrderByMemberIdColumn(
                $query,
                $direction,
                $memberIdColumn ?? $query->getModel()->getTable().'.member_id',
            );
        });
    }

    public static function relationName(?string $label = null): TextColumn
    {
        return self::name('member.name', $label)
            ->url(fn (mixed $state, mixed $record): ?string => self::relatedMemberEditUrl($record));
    }

    /**
     * Member number for installment (or other) rows nested under {@code loan.member}.
     */
    public static function loanMemberNumber(?string $label = null): TextColumn
    {
        $textColumn = TextColumn::make('loan.member.member_number');

        if ($label !== null) {
            $textColumn->label($label);
        } else {
            $textColumn->label(__('Member #'));
        }

        return $textColumn
            ->sortable(query: fn(Builder $query, string $direction): Builder => MemberNumberSettings::applyOrderByLoanInstallmentMember(
                $query,
                $direction,
            ))
            ->url(fn(mixed $state, mixed $record): ?string => self::resolveMemberUrl('loan.member.name', $record));
    }

    /**
     * Guarantor member number for loan rows ({@code guarantor.member_number} or nested {@code loan.guarantor.member_number}).
     */
    public static function guarantorNumber(
        string $column = 'guarantor.member_number',
        ?string $memberIdColumn = null,
        ?string $label = null,
    ): TextColumn {
        $nameColumn = str_starts_with($column, 'loan.')
            ? 'loan.guarantor.name'
            : 'guarantor.name';

        return self::relationNumberFor(
            memberNumberColumn: $column,
            memberIdColumn: $memberIdColumn,
            label: $label ?? __('Guarantor #'),
        )->url(fn(mixed $state, mixed $record): ?string => self::resolveMemberUrl($nameColumn, $record));
    }

    public static function applyArabicNameTypography(TextColumn $column): TextColumn
    {
        if (! ArabicDisplaySettings::enhancedNameStyle()) {
            return $column;
        }

        return $column
            ->html()
            ->formatStateUsing(
                fn ($state): Htmlable => ArabicTypography::display(
                    is_scalar($state) ? (string) $state : null,
                ),
            );
    }

    public static function memberRecordUrl(Member $record): string
    {
        return MemberResource::getUrl('view', ['record' => $record]);
    }

    public static function memberRecordEditUrl(Member $record): string
    {
        return self::memberRecordUrl($record);
    }

    public static function memberProfileEditUrl(Member $record): string
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
        return MemberResource::getUrl('view', ['record' => $record['member_id']]);
    }

    private static function extractMemberId(mixed $record): ?int
    {
        if (is_array($record)) {
            return isset($record['member_id']) ? (int) $record['member_id'] : null;
        }

        if (is_object($record) && isset($record->member_id)) {
            return (int) $record->member_id;
        }

        return null;
    }

    private static function resolveMemberModel(string $columnName, mixed $record): ?Member
    {
        if (! is_object($record)) {
            return null;
        }

        return match ($columnName) {
            'member.name', 'member.member_number' => $record->member instanceof Member ? $record->member : null,
            'loan.member.name', 'loan.member.member_number' => ($record->loan?->member ?? $record->member ?? null) instanceof Member
            ? ($record->loan?->member ?? $record->member)
            : null,
            'guarantor.name', 'guarantor.member_number' => $record->guarantor instanceof Member ? $record->guarantor : null,
            'loan.guarantor.name', 'loan.guarantor.member_number' => $record->loan?->guarantor instanceof Member
            ? $record->loan->guarantor
            : null,
            default => null,
        };
    }

    private static function qualifiedMemberNumberColumn(string $column): string
    {
        if (str_contains($column, '.')) {
            return $column;
        }

        return (new Member)->getTable().'.'.$column;
    }
}
