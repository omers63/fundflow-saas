<x-filament-panels::page>
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
            <div class="ff-member-profile-stats grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <x-member::stat-card :label="__('Member since')"
                    :value="$member->joined_at?->translatedFormat('d M Y') ?? '—'" />
                <x-member::stat-card :label="__('Monthly contribution')"
                    :amount="(float) $member->monthly_contribution_amount" />
                <x-member::stat-card :label="__('Fund balance')" :amount="$member->getFundBalance()" />
            </div>
        @endif

        @if ($householdProfiles->isNotEmpty() && $member?->isParent())
            <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-900">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('Household profiles') }}</h3>
                <p class="mt-1 text-xs text-gray-500">
                    {{ __('Switch to a dependent profile to manage their portal view.') }}
                </p>
                <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4">
                    @foreach ($householdProfiles as $profile)
                                        @php
                        $isCurrent = (int) $profile->user_id === (int) auth('tenant')->id();
                                        @endphp
                                        <div
                                            class="flex flex-col items-center rounded-xl border p-3 text-center {{ $isCurrent ? 'border-emerald-500 bg-emerald-50 dark:bg-emerald-950/30' : 'border-gray-200 dark:border-gray-700' }}">
                                            <span
                                                class="mb-2 flex h-12 w-12 items-center justify-center overflow-hidden rounded-full bg-gray-200 text-sm font-bold text-gray-600">
                                                @if ($profile->user?->avatarPublicUrl())
                                                    <img src="{{ $profile->user->avatarPublicUrl() }}" alt="" class="h-full w-full object-cover">
                                                @else
                                                    {{ strtoupper(mb_substr($profile->user?->name ?? $profile->name, 0, 1)) }}
                                                @endif
                                            </span>
                                            <span class="text-xs font-semibold text-gray-900 dark:text-white">{{ $profile->user?->name ?? $profile->name }}</span>
                                            <span class="mt-0.5 text-[10px] text-gray-500">
                                                {{ $profile->isParent() ? __('Parent') : __('Dependent') }}
                                            </span>
                                            @if ($isCurrent)
                                                <span class="mt-2 text-[10px] font-semibold text-emerald-600">{{ __('Current') }}</span>
                                            @elseif (!$profile->isParent())
                                                <a href="{{ route('tenant.member.dependents.impersonate', ['dependent' => $profile->id]) }}"
                                                    class="mt-2 text-[10px] font-semibold text-sky-600 hover:underline">
                                                    {{ __('Switch') }}
                                                </a>
                                            @endif
                                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        @if (session()->has('impersonator_user_id'))
            <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-950/40">
                <p class="text-sm font-semibold text-amber-800 dark:text-amber-200">{{ __('Impersonation active') }}</p>
                <p class="mt-1 text-xs text-amber-700 dark:text-amber-300">
                    {{ __('You are viewing the portal as a household member. Use "Return to parent portal" to switch back.') }}
                </p>
            </div>
        @endif
    </div>
</x-filament-panels::page>
