<?php

declare(strict_types=1);

namespace App\Services\Privacy;

use App\Models\Tenant\Contribution;
use App\Models\Tenant\Loan;
use App\Models\Tenant\Member;
use App\Models\Tenant\MemberPrivacyRequest;
use App\Models\Tenant\User;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

final class MemberPrivacyService
{
    /**
     * @return array<string, mixed>
     */
    public function buildExportPayload(Member $member): array
    {
        $member->loadMissing(['user', 'cashAccount', 'fundAccount']);

        return [
            'exported_at' => now()->toIso8601String(),
            'tenant_id' => tenant('id'),
            'member' => [
                'id' => $member->id,
                'member_number' => $member->member_number,
                'name' => $member->name,
                'email' => $member->email,
                'phone' => $member->phone,
                'status' => $member->status,
                'joined_at' => $member->joined_at?->toDateString(),
                'monthly_contribution_amount' => (float) ($member->monthly_contribution_amount ?? 0),
                'cash_balance' => round($member->getCashBalance(), 2),
                'fund_balance' => round($member->getFundBalance(), 2),
            ],
            'contributions' => Contribution::query()
                ->where('member_id', $member->id)
                ->orderBy('id')
                ->get(['id', 'period', 'amount', 'status', 'posted_at', 'paid_at', 'collection_status'])
                ->toArray(),
            'loans' => Loan::query()
                ->where('member_id', $member->id)
                ->orderBy('id')
                ->get(['id', 'status', 'amount_requested', 'amount_approved', 'amount_disbursed', 'approved_at', 'disbursed_at'])
                ->toArray(),
        ];
    }

    public function requestExport(Member $member): MemberPrivacyRequest
    {
        $payload = $this->buildExportPayload($member);
        $path = 'privacy-exports/member-'.$member->id.'-'.now()->format('YmdHis').'.json';
        Storage::disk('local')->put($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return MemberPrivacyRequest::query()->create([
            'member_id' => $member->id,
            'type' => MemberPrivacyRequest::TYPE_DATA_EXPORT,
            'status' => MemberPrivacyRequest::STATUS_COMPLETED,
            'export_meta' => [
                'bytes' => strlen(json_encode($payload, JSON_THROW_ON_ERROR)),
                'contribution_count' => count($payload['contributions']),
                'loan_count' => count($payload['loans']),
            ],
            'export_path' => $path,
            'completed_at' => now(),
        ]);
    }

    public function requestClosure(Member $member, string $reason): MemberPrivacyRequest
    {
        $open = MemberPrivacyRequest::query()
            ->where('member_id', $member->id)
            ->where('type', MemberPrivacyRequest::TYPE_ACCOUNT_CLOSURE)
            ->whereIn('status', [
                MemberPrivacyRequest::STATUS_PENDING,
                MemberPrivacyRequest::STATUS_APPROVED,
            ])
            ->exists();

        if ($open) {
            throw new InvalidArgumentException(__('A closure request is already pending.'));
        }

        return MemberPrivacyRequest::query()->create([
            'member_id' => $member->id,
            'type' => MemberPrivacyRequest::TYPE_ACCOUNT_CLOSURE,
            'status' => MemberPrivacyRequest::STATUS_PENDING,
            'reason' => $reason,
        ]);
    }

    public function approveClosure(MemberPrivacyRequest $request, User $admin, ?string $notes = null): MemberPrivacyRequest
    {
        if ($request->type !== MemberPrivacyRequest::TYPE_ACCOUNT_CLOSURE) {
            throw new InvalidArgumentException(__('Only closure requests can be approved for anonymization.'));
        }

        if ($request->status !== MemberPrivacyRequest::STATUS_PENDING) {
            throw new InvalidArgumentException(__('Request is not pending.'));
        }

        $request->forceFill([
            'status' => MemberPrivacyRequest::STATUS_APPROVED,
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
            'admin_notes' => $notes,
        ])->save();

        $this->anonymizeMember($request->member()->firstOrFail());

        $request->forceFill([
            'status' => MemberPrivacyRequest::STATUS_COMPLETED,
            'completed_at' => now(),
        ])->save();

        return $request->fresh();
    }

    public function reject(MemberPrivacyRequest $request, User $admin, ?string $notes = null): MemberPrivacyRequest
    {
        $request->forceFill([
            'status' => MemberPrivacyRequest::STATUS_REJECTED,
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
            'admin_notes' => $notes,
        ])->save();

        return $request->fresh();
    }

    public function anonymizeMember(Member $member): void
    {
        $token = 'anon-'.$member->id.'-'.now()->format('Ymd');

        $member->forceFill([
            'name' => __('Anonymized member #:id', ['id' => $member->id]),
            'email' => $token.'@anonymized.local',
            'phone' => null,
            'household_email' => null,
            'status' => 'withdrawn',
            'status_reason' => __('Anonymized after PDPL closure request'),
            'status_changed_at' => now(),
        ])->save();

        $user = $member->user;
        if ($user !== null) {
            $user->forceFill([
                'name' => __('Anonymized user #:id', ['id' => $user->id]),
                'email' => 'user-'.$token.'@anonymized.local',
                'phone' => null,
                'avatar_path' => null,
            ])->save();
        }
    }
}
