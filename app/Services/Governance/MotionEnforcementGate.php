<?php

declare(strict_types=1);

namespace App\Services\Governance;

use App\Models\Tenant\Motion;
use App\Support\GovernanceSettings;
use InvalidArgumentException;

final class MotionEnforcementGate
{
    /**
     * Require an approved setting_change motion when governance enforcement is enabled.
     *
     * @throws InvalidArgumentException
     */
    public function assertSettingChangeAllowed(string $group, string $key, ?int $motionId = null): void
    {
        if (! GovernanceSettings::settingChangeRequiresMotion()) {
            return;
        }

        if ($group === GovernanceSettings::GROUP) {
            return;
        }

        if (! in_array($group, GovernanceSettings::protectedSettingGroups(), true)) {
            return;
        }

        $motion = $this->requireApprovedMotion($motionId, Motion::TYPE_SETTING_CHANGE);

        $payload = $motion->payload ?? [];
        $payloadGroup = (string) ($payload['group'] ?? '');
        $payloadKey = (string) ($payload['key'] ?? '');

        if ($payloadGroup !== '' && $payloadGroup !== $group) {
            throw new InvalidArgumentException(__('Approved motion does not cover this setting group.'));
        }

        if ($payloadKey !== '' && $payloadKey !== $key) {
            throw new InvalidArgumentException(__('Approved motion does not cover this setting key.'));
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    public function assertExpenseAllowed(float $amount, ?int $motionId = null): void
    {
        $threshold = GovernanceSettings::expenseMotionThreshold();

        if ($amount <= $threshold) {
            return;
        }

        $this->requireApprovedMotion($motionId, Motion::TYPE_EXPENSE_APPROVAL, $amount);
    }

    /**
     * @throws InvalidArgumentException
     */
    public function assertDistributionAllowed(float $amount, ?int $motionId = null): void
    {
        $threshold = GovernanceSettings::distributionMotionThreshold();

        if ($amount <= $threshold) {
            return;
        }

        $this->requireApprovedMotion($motionId, Motion::TYPE_DISTRIBUTION_RUN, $amount);
    }

    private function requireApprovedMotion(?int $motionId, string $type, ?float $amount = null): Motion
    {
        if ($motionId === null) {
            throw new InvalidArgumentException(__('An approved motion is required for this action.'));
        }

        $motion = Motion::query()->find($motionId);

        if ($motion === null || $motion->status !== Motion::STATUS_APPROVED) {
            throw new InvalidArgumentException(__('Motion #:id is not approved.', ['id' => $motionId]));
        }

        if ($motion->type !== $type && $motion->type !== Motion::TYPE_GENERIC) {
            throw new InvalidArgumentException(__('Motion type does not match this action.'));
        }

        if ($amount !== null && $motion->amount_threshold !== null && $amount > (float) $motion->amount_threshold) {
            throw new InvalidArgumentException(__('Amount exceeds the motion approval threshold.'));
        }

        return $motion;
    }
}
