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

    @if ($householdProfiles->isNotEmpty() && $member?->isParent())
        <x-member::panel :title="__('Household profiles')">
            <p class="ff-member-dashboard-meta mb-3">
                {{ __('Switch to a dependent profile to manage their portal view.') }}
            </p>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4">
                @foreach ($householdProfiles as $profile)
                                @php
        $isCurrent = (int) $profile->user_id === (int) auth('tenant')->id();
                                @endphp
                    <div @class([
            'flex flex-col items-center rounded-xl border p-3 text-center',
            'border-emerald-500 bg-emerald-50 dark:border-emerald-400/50 dark:bg-emerald-950/40' => $isCurrent,
            'border-gray-200 dark:border-white/10' => !$isCurrent,
        ])>
                        <span
                            class="mb-2 flex h-12 w-12 items-center justify-center overflow-hidden rounded-full bg-gray-200 text-sm font-bold text-gray-600 dark:bg-white/10 dark:text-gray-300">
                            @if ($profile->user?->avatarPublicUrl())
                                <img src="{{ $profile->user->avatarPublicUrl() }}" alt="" class="h-full w-full object-cover">
                            @else
                                {{ strtoupper(mb_substr($profile->user?->name ?? $profile->name, 0, 1)) }}
                            @endif
                        </span>
                        <span class="text-xs font-semibold text-gray-900 dark:text-gray-100">{{ $profile->user?->name ?? $profile->name }}</span>
                        <span class="mt-0.5 text-[10px] text-gray-500 dark:text-gray-400">
                            {{ $profile->isParent() ? __('Parent') : __('Dependent') }}
                        </span>
                        @if ($isCurrent)
                            <span class="mt-2 text-[10px] font-semibold text-emerald-600 dark:text-emerald-400">{{ __('Current') }}</span>
                        @elseif (!$profile->isParent())
                            <a href="{{ route('tenant.member.dependents.impersonate', ['dependent' => $profile->id]) }}"
                                class="mt-2 text-[10px] font-semibold text-sky-600 hover:underline dark:text-sky-400">
                                {{ __('Switch') }}
                            </a>
                        @endif
                                </div>
                @endforeach
            </div>
        </x-member::panel>
    @endif

    @if (session()->has('impersonator_user_id'))
        @php($impersonation = app(\App\Services\Tenant\ImpersonationService::class))
        <x-member::notice tone="amber" :title="__('Impersonation active')">
            <p class="m-0">
                {{ $impersonation->isAdminImpersonation()
        ? __('You are viewing the portal as this member. Use ":action" to switch back.', ['action' => $impersonation->returnActionLabel()])
        : __('You are viewing the portal as a household member. Use "Return to parent portal" to switch back.') }}
            </p>
        </x-member::notice>
    @endif
</div>