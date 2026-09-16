<?php

declare(strict_types=1);

namespace App\Support\Billing;

use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Grace / read-only enforcement for expired SaaS subscriptions.
 */
final class TenantBillingStatus
{
    public const MODE_ACTIVE = 'active';

    public const MODE_GRACE = 'grace';

    public const MODE_READ_ONLY = 'read_only';

    /** Days after subscription ends before read-only. */
    public const GRACE_DAYS = 7;

    public function __construct(
        private readonly TenantFeatureGate $features,
    ) {}

    public function mode(): string
    {
        $subscription = $this->features->subscription();

        if ($subscription === null) {
            return self::MODE_ACTIVE;
        }

        if ($subscription->isActive()) {
            return self::MODE_ACTIVE;
        }

        $endsAt = $subscription->ends_at;
        if (! $endsAt instanceof Carbon) {
            return self::MODE_READ_ONLY;
        }

        $graceEnds = $endsAt->copy()->addDays(self::GRACE_DAYS);

        if (now()->lte($graceEnds)) {
            return self::MODE_GRACE;
        }

        return self::MODE_READ_ONLY;
    }

    public function isReadOnly(): bool
    {
        return $this->mode() === self::MODE_READ_ONLY;
    }

    public function canMutateMoney(): bool
    {
        return ! $this->isReadOnly();
    }

    public function assertCanMutateMoney(): void
    {
        if (! $this->canMutateMoney()) {
            throw new InvalidArgumentException(
                __('Tenant billing is past the grace period. Money posting is read-only until the subscription is renewed.'),
            );
        }
    }
}
