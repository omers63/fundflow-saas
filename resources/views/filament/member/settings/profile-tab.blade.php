@php
use App\Models\Tenant\Member;

$user = $user ?? auth('tenant')->user();
$member = $member ?? $user?->member;
@endphp

<div class="space-y-6">
    @if ($member)
        <x-member::detail-grid :items="[
                ['label' => __('Member number'), 'value' => $member->member_number],
                [
                    'label' => __('Member since'),
                    'value' => $member->joined_at?->locale(app()->getLocale())->translatedFormat('d M Y') ?? '—',
                ],
                [
                    'label' => __('Status'),
                    'value' => Member::statusOptions()[$member->status] ?? $member->status,
                ],
            ]" />
    @endif

    <form wire:submit="saveProfile">
        {{ $this->form }}

        <div class="mt-6">
            <x-filament::button type="submit">
                {{ __('Save account details') }}
            </x-filament::button>
        </div>
    </form>

    <x-member::panel :title="__('Payout bank details')">
        @if (filled($payoutIban))
            <x-member::detail-grid :items="[
                ['label' => __('Registered IBAN'), 'value' => $payoutIban],
            ]" />
            <p class="ff-member-dashboard-meta mt-3 mb-0">
                {{ __('Cash-out withdrawals are sent to this IBAN. Contact support to update your payout details.') }}
            </p>
        @else
            <x-member::notice tone="blue">
                <p class="m-0">
                    {{ __('No payout IBAN is on file. Contact fund administrators to register your bank account for cash-out withdrawals.') }}
                </p>
            </x-member::notice>
        @endif
    </x-member::panel>

    @if ($householdProfiles->isNotEmpty() && $member?->isParent())
        <x-member::panel :title="__('Household profiles')">
            <p class="ff-member-dashboard-meta mb-3">
                {{ __('Switch to a dependent profile to manage their portal view.') }}
            </p>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4">
                @foreach ($householdProfiles as $profile)
                    @php
                        $isCurrent = (int) $profile->user_id === (int) auth('tenant')->id();
                    @endphp
                    <div @class([
                        'flex flex-col items-center rounded-xl border p-3 text-center',
                        'border-emerald-500 bg-emerald-50' => $isCurrent,
                        'border-gray-200' => !$isCurrent,
                    ])>
                        <span
                            class="mb-2 flex h-12 w-12 items-center justify-center overflow-hidden rounded-full bg-gray-200 text-sm font-bold text-gray-600">
                            @if ($profile->user?->avatarPublicUrl())
                                <img src="{{ $profile->user->avatarPublicUrl() }}" alt="" class="h-full w-full object-cover">
                            @else
                                {{ strtoupper(mb_substr($profile->user?->name ?? $profile->name, 0, 1)) }}
                            @endif
                        </span>
                        <span class="text-xs font-semibold text-gray-900">{{ $profile->user?->name ?? $profile->name }}</span>
                        <span class="mt-0.5 text-[10px] text-gray-500">
                            {{ $profile->isParent() ? __('Parent') : __('Dependent') }}
                        </span>
                        @if ($isCurrent)
                            <span class="mt-2 text-[10px] font-semibold text-emerald-600">{{ __('Current') }}</span>
                        @elseif (!$profile->isParent())
                            @if ($profile->is_separated)
                                <button type="button"
                                    wire:click="mountAction('switchHouseholdProfile', { memberId: {{ $profile->id }} })"
                                    class="mt-2 text-[10px] font-semibold text-sky-600 hover:underline">
                                    {{ __('Switch') }}
                                </button>
                            @else
                                <a href="{{ route('tenant.member.dependents.impersonate', ['dependent' => $profile->id]) }}"
                                    class="mt-2 text-[10px] font-semibold text-sky-600 hover:underline">
                                    {{ __('Switch') }}
                                </a>
                            @endif
                        @endif
                    </div>
                @endforeach
            </div>
        </x-member::panel>
    @endif

    @if (session()->has('impersonator_user_id'))
        <x-member::notice tone="amber" :title="__('Impersonation active')">
            <p class="m-0">
                {{ __('You are viewing the portal as a household member. Use "Return to parent portal" to switch back.') }}
            </p>
        </x-member::notice>
    @endif
</div>