@php
    use App\Models\Tenant\Member;

    $user = $user ?? auth('tenant')->user();
    $member = $member ?? $user?->member;
@endphp

<div class="space-y-6">
    <div class="member-profile-identity-card">
        <div class="flex flex-col gap-5 sm:flex-row sm:items-center">
            <div
                class="relative flex h-20 w-20 shrink-0 items-center justify-center overflow-hidden rounded-full border-2 border-white/30 bg-white/20 text-3xl font-bold text-white select-none ring-2 ring-white/30">
                @if ($url = $user?->avatarPublicUrl())
                    <img src="{{ $url }}" alt="{{ $user?->name }}" class="absolute inset-0 h-full w-full object-cover">
                @else
                    {{ strtoupper(mb_substr($user?->name ?? '?', 0, 1)) }}
                @endif
            </div>
            <div class="min-w-0 flex-1">
                <p class="member-profile-identity-heading">
                    <x-arabic-text :text="$user?->name" />
                </p>
                @if ($member)
                    <p class="member-profile-identity-muted mt-1 font-mono text-sm">{{ $member->member_number }}</p>
                @endif
                <div class="mt-2 flex flex-wrap gap-3 text-sm">
                    <span class="member-profile-identity-muted flex items-center gap-1">
                        <x-heroicon-o-envelope class="h-4 w-4 shrink-0" />
                        {{ $user?->email }}
                    </span>
                    @if ($user?->phone)
                        <span class="member-profile-identity-muted flex items-center gap-1">
                            <x-heroicon-o-phone class="h-4 w-4 shrink-0" />
                            {{ $user->phone }}
                        </span>
                    @endif
                </div>
            </div>
            @if ($member)
                <div class="shrink-0">
                    <span
                        class="member-profile-status {{ $member->status === 'active' ? 'member-profile-status--active' : 'member-profile-status--inactive' }}">
                        {{ Member::statusOptions()[$member->status] ?? $member->status }}
                    </span>
                </div>
            @endif
        </div>
    </div>

    @if ($member)
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <x-member::stat-card :label="__('Member since')"
                :value="$member->joined_at?->locale(app()->getLocale())->translatedFormat('d M Y') ?? '—'" />
            <x-member::stat-card :label="__('Monthly contribution')" :amount="(float) $member->monthly_contribution_amount" />
            <x-member::stat-card :label="__('Fund balance')" :amount="$member->getFundBalance()" />
        </div>
    @endif

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