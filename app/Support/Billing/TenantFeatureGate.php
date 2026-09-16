<?php

declare(strict_types=1);

namespace App\Support\Billing;

use App\Models\Central\Plan;
use App\Models\Central\Subscription;
use App\Models\Central\Tenant;
use App\Models\Tenant\Member;

/**
 * Plan feature flags and SaaS billing status (central Plan/Subscription).
 */
final class TenantFeatureGate
{
    public const FEATURE_GATEWAY = 'gateway';

    public const FEATURE_API = 'api';

    public const FEATURE_OCR = 'ocr';

    public const FEATURE_WEBHOOKS = 'webhooks';

    /**
     * Default features when plan.data has no features key (grandfather existing tenants).
     *
     * @var list<string>
     */
    public const DEFAULT_FEATURES = [
        self::FEATURE_GATEWAY,
        self::FEATURE_API,
        self::FEATURE_OCR,
        self::FEATURE_WEBHOOKS,
    ];

    public function allows(string $feature): bool
    {
        $features = $this->features();

        return in_array($feature, $features, true);
    }

    /**
     * @return list<string>
     */
    public function features(): array
    {
        $plan = $this->plan();

        if ($plan === null) {
            return self::DEFAULT_FEATURES;
        }

        $data = is_array($plan->data) ? $plan->data : [];
        $features = $data['features'] ?? null;

        if (! is_array($features)) {
            return self::DEFAULT_FEATURES;
        }

        return array_values(array_map('strval', $features));
    }

    public function maxMembers(): ?int
    {
        $plan = $this->plan();
        if ($plan === null) {
            return null;
        }

        $data = is_array($plan->data) ? $plan->data : [];
        $max = $data['max_members'] ?? null;

        return is_numeric($max) ? (int) $max : null;
    }

    public function activeMemberCount(): int
    {
        return (int) Member::query()->where('status', 'active')->count();
    }

    public function plan(): ?Plan
    {
        $tenant = tenant();
        if (! $tenant instanceof Tenant) {
            return null;
        }

        $tenant->loadMissing('plan');

        return $tenant->plan;
    }

    public function subscription(): ?Subscription
    {
        $tenant = tenant();
        if (! $tenant instanceof Tenant) {
            return null;
        }

        $tenant->loadMissing('subscription');

        return $tenant->subscription;
    }
}
