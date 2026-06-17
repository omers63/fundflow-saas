<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant\Member;
use Illuminate\Database\Eloquent\Collection;

/**
 * Resolves legacy CSV member identifiers that may be numeric member numbers
 * or household shorthand names (often stored in parent_member_number).
 */
final class LegacyMemberIdentifierResolver
{
    public function findByMemberNumber(string $memberNumber): ?Member
    {
        $memberNumber = trim($memberNumber);

        if ($memberNumber === '') {
            return null;
        }

        return Member::query()->where('member_number', $memberNumber)->first();
    }

    public function findByEmail(string $email): ?Member
    {
        $email = strtolower(trim($email));

        if ($email === '') {
            return null;
        }

        return Member::query()->whereRaw('LOWER(email) = ?', [$email])->first();
    }

    public function findByName(string $name): ?Member
    {
        $name = trim($name);

        if ($name === '') {
            return null;
        }

        return $this->pickSingleMatch(
            Member::query()
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                ->get(),
        );
    }

    /**
     * Resolve a member number or legacy household label to a member row.
     */
    public function findByNumberOrLegacyLabel(string $identifier): ?Member
    {
        $identifier = trim($identifier);

        if ($identifier === '') {
            return null;
        }

        $byNumber = $this->findByMemberNumber($identifier);

        if ($byNumber !== null) {
            return $byNumber;
        }

        $byExactName = $this->findByName($identifier);

        if ($byExactName !== null) {
            return $byExactName;
        }

        return $this->findByLegacyHouseholdLabel($identifier);
    }

    /**
     * Legacy exports often put a shortened household head name in parent_member_number.
     */
    public function findByLegacyHouseholdLabel(string $label): ?Member
    {
        $label = trim($label);

        if ($label === '') {
            return null;
        }

        $normalized = mb_strtolower($label);
        $words = preg_split('/\s+/u', $label, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($words !== []) {
            $first = mb_strtolower($words[0]);
            $last = mb_strtolower($words[array_key_last($words)]);

            $edgeMatches = Member::query()
                ->whereRaw('LOWER(name) LIKE ?', [$first.'%'])
                ->whereRaw('LOWER(name) LIKE ?', ['%'.$last])
                ->get();

            $picked = $this->pickSingleMatch($edgeMatches);

            if ($picked !== null) {
                return $picked;
            }
        }

        $containsMatches = Member::query()
            ->whereRaw('LOWER(name) LIKE ?', ['%'.$normalized.'%'])
            ->get();

        return $this->pickSingleMatch($containsMatches);
    }

    /**
     * @param  Collection<int, Member>  $matches
     */
    private function pickSingleMatch(Collection $matches): ?Member
    {
        if ($matches->isEmpty()) {
            return null;
        }

        if ($matches->count() === 1) {
            return $matches->first();
        }

        $withoutParent = $matches->filter(fn (Member $member): bool => $member->parent_member_id === null);

        if ($withoutParent->count() === 1) {
            return $withoutParent->first();
        }

        return $matches
            ->sortBy(fn (Member $member): int => mb_strlen($member->name))
            ->first();
    }
}
