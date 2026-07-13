<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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

    /**
     * @return list<string|ValidationRule>
     */
    public function rulesForNewLoginEmail(?int $exceptUserId = null): array
    {
        return [
            'required',
            'email',
            'max:255',
            Rule::unique(User::class, 'email')->ignore($exceptUserId),
            function (string $attribute, mixed $value, Closure $fail): void {
                if (! $this->isDeliverableEmail((string) $value)) {
                    $fail(__('Enter a valid email address.'));
                }
            },
        ];
    }

    /**
     * @throws ValidationException
     */
    public function validateNewLoginEmail(string $email, ?int $exceptUserId = null, string $field = 'new_email'): string
    {
        $normalized = strtolower(trim($email));

        $validator = Validator::make(
            [$field => $normalized],
            [$field => $this->rulesForNewLoginEmail($exceptUserId)],
            [
                "{$field}.required" => __('Enter a unique email for your login before requesting a dependent.'),
                "{$field}.email" => __('Enter a valid email address.'),
                "{$field}.unique" => __('This email is already in use. Choose another.'),
            ],
        );

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $normalized;
    }
}
