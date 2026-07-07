<?php

declare(strict_types=1);

namespace App\Services\Tenant;

use App\Models\Tenant\Member;
use App\Models\Tenant\MembershipApplication;
use App\Models\Tenant\User;

final class MemberMembershipProfileService
{
    /** @var list<string> */
    public const PROFILE_ATTRIBUTES = [
        'gender',
        'marital_status',
        'national_id',
        'date_of_birth',
        'city',
        'address',
        'mobile_phone',
        'home_phone',
        'work_phone',
        'work_place',
        'residency_place',
        'occupation',
        'employer',
        'monthly_income',
        'bank_account_number',
        'iban',
        'next_of_kin_name',
        'next_of_kin_phone',
        'message',
        'membership_fee_amount',
        'membership_fee_transfer_date',
        'membership_fee_transfer_reference',
        'membership_fee_receipt_path',
        'application_form_path',
    ];

    public function findForMember(Member $member): ?MembershipApplication
    {
        $application = MembershipApplication::query()
            ->where('member_id', $member->id)
            ->where('status', 'approved')
            ->latest('id')
            ->first();

        if ($application !== null) {
            return $application;
        }

        if (! filled($member->email)) {
            return null;
        }

        $application = MembershipApplication::query()
            ->where('email', $member->email)
            ->where('status', 'approved')
            ->latest('id')
            ->first();

        if ($application !== null && $application->member_id === null) {
            $application->update(['member_id' => $member->id]);
        }

        return $application?->fresh();
    }

    public function resolveForMember(Member $member): MembershipApplication
    {
        return $this->findForMember($member) ?? MembershipApplication::query()->create([
            'member_id' => $member->id,
            'name' => $member->name,
            'email' => $member->email,
            'phone' => $member->phone,
            'mobile_phone' => $member->phone,
            'status' => 'approved',
            'application_type' => 'new',
            'reviewed_at' => now(),
            'membership_date' => $member->joined_at,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function syncFromPortalForm(Member $member, User $user, array $data): MembershipApplication
    {
        $application = $this->resolveForMember($member);

        $attributes = collect($data)
            ->only(self::PROFILE_ATTRIBUTES)
            ->map(function (mixed $value, string $key): mixed {
                if ($key === 'iban' && filled($value)) {
                    return strtoupper((string) $value);
                }

                return $value;
            })
            ->all();

        $mobilePhone = filled($attributes['mobile_phone'] ?? null)
            ? (string) $attributes['mobile_phone']
            : (string) ($user->phone ?? $member->phone ?? '');

        if ($mobilePhone !== '') {
            $attributes['mobile_phone'] = $mobilePhone;
            $attributes['phone'] = $mobilePhone;
        }

        $application->update([
            ...$attributes,
            'name' => $user->name,
            'email' => $user->email,
        ]);

        if ($mobilePhone !== '' && (string) $member->phone !== $mobilePhone) {
            $member->update(['phone' => $mobilePhone]);
        }

        if ($mobilePhone !== '' && (string) $user->phone !== $mobilePhone) {
            $user->update(['phone' => $mobilePhone]);
        }

        return $application->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    public function formState(?MembershipApplication $application, User $user): array
    {
        if ($application === null) {
            return filled($user->phone)
                ? ['mobile_phone' => $user->phone]
                : [];
        }

        $state = $application->only(self::PROFILE_ATTRIBUTES);

        if (blank($state['mobile_phone'] ?? null) && filled($user->phone)) {
            $state['mobile_phone'] = $user->phone;
        }

        return $state;
    }
}
