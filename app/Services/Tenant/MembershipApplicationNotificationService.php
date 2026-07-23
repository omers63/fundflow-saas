<?php

declare(strict_types=1);

namespace App\Services\Tenant;

use App\Models\Tenant\Member;
use App\Models\Tenant\MembershipApplication;
use App\Notifications\Tenant\MembershipApplicationApprovedNotification;
use App\Notifications\Tenant\MembershipApplicationRejectedNotification;
use App\Notifications\Tenant\NewMembershipApplicationNotification;
use App\Services\OperationalReviewWorkflowService;
use Illuminate\Support\Facades\Notification;
use Throwable;

final class MembershipApplicationNotificationService
{
    public function __construct(
        private readonly OperationalReviewWorkflowService $reviewWorkflow,
        private readonly MemberOnboardingGreetingService $onboardingGreetings,
    ) {}

    public function notifyAdminsOfSubmission(MembershipApplication $application): void
    {
        try {
            $this->reviewWorkflow->notifyAdmins(new NewMembershipApplicationNotification($application));
        } catch (Throwable $e) {
            logger()->warning('MembershipApplicationNotificationService: admin submit alert failed', [
                'application_id' => $application->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function notifyApplicantApproved(MembershipApplication $application, Member $member): void
    {
        try {
            $member->loadMissing('user');
            $member->user?->notify(new MembershipApplicationApprovedNotification($application, $member));
            $this->onboardingGreetings->sendToMember($member);
        } catch (Throwable $e) {
            logger()->warning('MembershipApplicationNotificationService: approval alert failed', [
                'application_id' => $application->id,
                'member_id' => $member->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function notifyApplicantRejected(MembershipApplication $application, ?string $reason = null): void
    {
        $email = trim((string) ($application->email ?? $application->household_email ?? ''));

        if ($email === '') {
            return;
        }

        try {
            Notification::route('mail', $email)
                ->notify(new MembershipApplicationRejectedNotification($application, $reason));
        } catch (Throwable $e) {
            logger()->warning('MembershipApplicationNotificationService: rejection alert failed', [
                'application_id' => $application->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
