<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant\User;
use Illuminate\Support\Str;

final class MemberUserEmail
{
    public const INTERNAL_DOMAIN = 'household.members.local';

    public function resolveForNewMember(string $preferredEmail): string
    {
        $preferred = strtolower(trim($preferredEmail));

        if ($preferred !== '' && ! $this->isTaken($preferred)) {
            return $preferred;
        }

        return $this->generateInternalLoginEmail();
    }

    public function resolveForUserEmailChange(string $preferredEmail, int $exceptUserId): string
    {
        $preferred = strtolower(trim($preferredEmail));

        if ($preferred !== '' && ! $this->isTaken($preferred, $exceptUserId)) {
            return $preferred;
        }

        return $this->generateInternalLoginEmail();
    }

    public function isInternalLoginEmail(string $email): bool
    {
        return str_ends_with(strtolower(trim($email)), '@'.self::INTERNAL_DOMAIN);
    }

    public function isSyntheticImportLoginEmail(string $email): bool
    {
        return str_ends_with(strtolower(trim($email)), '@import.fundflow.local');
    }

    public function isDeliverableEmail(string $email): bool
    {
        $email = strtolower(trim($email));

        return $email !== ''
            && ! $this->isInternalLoginEmail($email)
            && ! $this->isSyntheticImportLoginEmail($email);
    }

    public function deliverableEmailFor(User $user): ?string
    {
        $user->loadMissing('member');

        $candidates = [
            (string) $user->email,
            (string) ($user->member?->email ?? ''),
            (string) ($user->member?->household_email ?? ''),
        ];

        foreach ($candidates as $candidate) {
            if ($this->isDeliverableEmail($candidate)) {
                return strtolower(trim($candidate));
            }
        }

        return null;
    }

    public function generateInternalLoginEmail(): string
    {
        do {
            $email = 'member.'.Str::lower(Str::random(20)).'@'.self::INTERNAL_DOMAIN;
        } while ($this->isTaken($email));

        return $email;
    }

    public function isTaken(string $email, ?int $exceptUserId = null): bool
    {
        return User::query()
            ->when($exceptUserId !== null, fn ($query) => $query->whereKeyNot($exceptUserId))
            ->where('email', strtolower(trim($email)))
            ->exists();
    }
}
