<?php

declare(strict_types=1);

namespace App\Services\Tenant;

use App\Models\Tenant\Member;
use App\Models\Tenant\SupportRequest;
use App\Models\Tenant\SupportRequestReply;
use App\Models\Tenant\User;
use App\Notifications\Tenant\SupportRequestStatusNotification;
use App\Support\BusinessDay;
use Illuminate\Support\Facades\DB;

final class SupportRequestWorkflowService
{
    public function __construct(
        private DirectMessagingService $messaging,
    ) {}

    public function updateStatus(SupportRequest $request, string $status, ?User $actor = null): SupportRequest
    {
        $previousStatus = $request->status;
        $request->status = $status;

        if (in_array($status, [SupportRequest::STATUS_RESOLVED, SupportRequest::STATUS_CLOSED], true)) {
            $request->resolved_at ??= BusinessDay::now();
        } else {
            $request->resolved_at = null;
        }

        if ($status === SupportRequest::STATUS_IN_PROGRESS && $actor !== null) {
            $request->assigned_to_user_id ??= $actor->id;
        }

        $request->save();

        if ($previousStatus !== $status) {
            $this->notifyMemberOfStatusChange($request->fresh(['member.user']), $status);
        }

        return $request->fresh(['member.user', 'replies.user', 'assignedTo']);
    }

    public function escalate(SupportRequest $request): SupportRequest
    {
        $request->escalated_at = BusinessDay::now();
        $request->save();

        return $request->fresh(['member.user', 'replies.user', 'assignedTo']);
    }

    public function clearEscalation(SupportRequest $request): SupportRequest
    {
        $request->escalated_at = null;
        $request->save();

        return $request->fresh(['member.user', 'replies.user', 'assignedTo']);
    }

    public function addReply(SupportRequest $request, User $admin, string $body, bool $notifyMember = true): SupportRequestReply
    {
        $body = trim($body);

        if ($body === '') {
            throw new \InvalidArgumentException(__('Reply body is required.'));
        }

        return DB::transaction(function () use ($request, $admin, $body, $notifyMember): SupportRequestReply {
            $reply = $request->replies()->create([
                'user_id' => $admin->id,
                'body' => $body,
            ]);

            if ($request->status === SupportRequest::STATUS_OPEN) {
                $this->updateStatus($request, SupportRequest::STATUS_IN_PROGRESS, $admin);
            }

            if ($notifyMember && $request->member instanceof Member && filled($request->member->user_id)) {
                $this->messaging->sendAdminToMember(
                    $request->member,
                    $admin,
                    $body,
                    [],
                    suppressAdminToast: true,
                    subject: __('Re: :subject', ['subject' => $request->subject]),
                );
            }

            return $reply->load('user');
        });
    }

    public function slaLabel(SupportRequest $request): string
    {
        $days = $request->daysOpen();

        return trans_choice(':count day open|:count days open', $days, ['count' => $days]);
    }

    private function notifyMemberOfStatusChange(SupportRequest $request, string $status): void
    {
        if (! in_array($status, [SupportRequest::STATUS_RESOLVED, SupportRequest::STATUS_CLOSED, SupportRequest::STATUS_IN_PROGRESS], true)) {
            return;
        }

        try {
            $request->loadMissing('member.user');
            $request->member?->user?->notify(new SupportRequestStatusNotification($request, $status));
        } catch (\Throwable $e) {
            logger()->warning('SupportRequestWorkflowService: status notification failed', [
                'support_request_id' => $request->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
