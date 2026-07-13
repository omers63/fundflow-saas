<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\MemberUserEmail;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class NewMemberLoginEmail implements ValidationRule
{
    public function __construct(
        private readonly ?int $exceptUserId = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $email = app(MemberUserEmail::class);

        if (! $email->isDeliverableEmail((string) $value)) {
            $fail(__('Enter a valid email address.'));

            return;
        }

        if ($email->isTaken((string) $value, $this->exceptUserId)) {
            $fail(__('This email is already in use. Choose another.'));
        }
    }
}
