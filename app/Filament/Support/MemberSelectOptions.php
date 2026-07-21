<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Tenant\Member;
use Illuminate\Database\Eloquent\Builder;

/**
 * Shared member option labels for Filament selects and filters.
 *
 * Format: {@code member_number - member_name} so admins can search by either.
 */
final class MemberSelectOptions
{
    public static function label(Member $member): string
    {
        $number = trim((string) ($member->member_number ?? ''));
        $name = trim((string) ($member->name ?? ''));

        if ($number === '') {
            return $name !== '' ? $name : (string) $member->getKey();
        }

        if ($name === '') {
            return $number;
        }

        return "{$number} - {$name}";
    }

    /**
     * @return array<int|string, string>
     */
    public static function options(
        ?Builder $query = null,
        bool $activeOnly = false,
        ?int $limit = null,
    ): array {
        $query = self::baseQuery($query, $activeOnly);

        if ($limit !== null) {
            $query->limit($limit);
        }

        return $query
            ->get(['id', 'member_number', 'name'])
            ->mapWithKeys(fn (Member $member): array => [
                $member->id => self::label($member),
            ])
            ->all();
    }

    /**
     * @return array<int|string, string>
     */
    public static function activeOptions(?int $limit = null): array
    {
        return self::options(activeOnly: true, limit: $limit);
    }

    /**
     * @return array<int|string, string>
     */
    public static function search(
        string $search,
        bool $activeOnly = false,
        int $limit = 75,
        ?Builder $query = null,
    ): array {
        $query = self::baseQuery($query, $activeOnly);
        $search = trim($search);

        if ($search !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';

            $query->where(function (Builder $builder) use ($like): void {
                $builder
                    ->where('member_number', 'like', $like)
                    ->orWhere('name', 'like', $like);
            });
        }

        return $query
            ->limit($limit)
            ->get(['id', 'member_number', 'name'])
            ->mapWithKeys(fn (Member $member): array => [
                $member->id => self::label($member),
            ])
            ->all();
    }

    public static function labelForId(int|string|null $id): ?string
    {
        if (blank($id)) {
            return null;
        }

        $member = Member::query()->find($id);

        return $member instanceof Member ? self::label($member) : null;
    }

    private static function baseQuery(?Builder $query, bool $activeOnly): Builder
    {
        $query ??= Member::query();

        if ($activeOnly) {
            $query->active();
        }

        return $query
            ->orderBy('member_number')
            ->orderBy('name');
    }
}
