<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Member;
use InvalidArgumentException;

final class MemberStatusService
{
    public function __construct(
        private readonly FundAuditLogService $audit,
    ) {}

    public function suspend(Member $member, string $reason = ''): void
    {
        if ($member->status === 'suspended') {
            throw new InvalidArgumentException(__('Member is already suspended.'));
        }

        if (in_array($member->status, ['withdrawn', 'terminated'], true)) {
            throw new InvalidArgumentException(__('Cannot suspend a withdrawn or terminated member.'));
        }

        $previousStatus = $member->status;

        $member->update(['status' => 'suspended']);

        $this->audit->log('MEMBER_SUSPENDED', 'member', $member, $member, [
            'previous_status' => $previousStatus,
            'reason' => trim($reason),
        ]);
    }

    public function restoreSuspended(Member $member): void
    {
        if ($member->status !== 'suspended') {
            throw new InvalidArgumentException(__('Member is not suspended.'));
        }

        $member->update(['status' => 'active']);

        $this->audit->log('MEMBER_RESTORED', 'member', $member, $member, [
            'previous_status' => 'suspended',
        ]);
    }

    public function terminate(Member $member, string $reason = ''): void
    {
        if ($member->status === 'terminated') {
            throw new InvalidArgumentException(__('Member is already terminated.'));
        }

        $previousStatus = $member->status;

        $member->update(['status' => 'terminated']);

        $this->audit->log('MEMBER_TERMINATED', 'member', $member, $member, [
            'previous_status' => $previousStatus,
            'reason' => trim($reason),
        ]);
    }
}
