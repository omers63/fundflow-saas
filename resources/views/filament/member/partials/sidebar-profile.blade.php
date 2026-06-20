@php
    use App\Filament\Member\Pages\MemberSettingsPage;
    use App\Models\Tenant\User;
    use App\Support\MemberDateDisplay;
    use App\Support\Tenant\CurrentMember;

    $user = auth('tenant')->user();
    $member = CurrentMember::get();

    $initials = collect(preg_split('/\s+/u', trim((string) ($user?->name ?? ''), " \t\n\r\0\x0B"), -1, PREG_SPLIT_NO_EMPTY))
        ->take(2)
        ->map(fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)))
        ->join('') ?: '?';

    $sinceDate = MemberDateDisplay::format($member?->joined_at, 'M Y');
    $sidebarCollapsible = filament()->isSidebarCollapsibleOnDesktop() || filament()->isSidebarFullyCollapsibleOnDesktop();
@endphp

@if ($user instanceof User && $member !== null)
    <a
        href="{{ MemberSettingsPage::getUrl(['tab' => 'profile']) }}"
        wire:navigate
        @class(['ff-member-sidebar-profile'])
        @if ($sidebarCollapsible)
            x-data="{ tooltip: false }"
            x-effect="
                tooltip = $store.sidebar.isOpen
                    ? false
                    : {
                          content: @js($user->name),
                          placement: document.dir === 'rtl' ? 'left' : 'right',
                          theme: $store.theme,
                      }
            "
            x-tooltip.html="tooltip"
        @endif
    >
        <span class="ff-member-sidebar-profile__avatar" aria-hidden="true">
            @if ($avatarUrl = $user->avatarPublicUrl())
                <img src="{{ $avatarUrl }}" alt="" class="ff-member-sidebar-profile__avatar-image">
            @else
                {{ $initials }}
            @endif
        </span>

        <span
            class="ff-member-sidebar-profile__meta"
            @if ($sidebarCollapsible)
                x-show="$store.sidebar.isOpen"
                x-transition:enter="fi-transition-enter"
                x-transition:enter-start="fi-transition-enter-start"
                x-transition:enter-end="fi-transition-enter-end"
            @endif
        >
            <span class="ff-member-sidebar-profile__name">
                <x-arabic-text :text="$user->name" />
            </span>

            @if (filled($member->member_number))
                <span class="ff-member-sidebar-profile__subtitle">
                    {{ __('Member #:number · since :date', [
                        'number' => $member->member_number,
                        'date' => $sinceDate ?? '—',
                    ]) }}
                </span>
            @endif

            <x-member::chip
                :variant="$member->status === 'active' ? 'green' : 'amber'"
                class="ff-member-sidebar-profile__status"
            >
                <span class="ff-member-sidebar-profile__status-dot" aria-hidden="true"></span>
                {{ __(ucfirst(str_replace('_', ' ', (string) $member->status))) }}
            </x-member::chip>
        </span>
    </a>
@endif
