@php
use App\Support\BusinessDay;
use App\Support\PublicPageSettings;
use App\Services\Tenant\ImpersonationService;

// Always rendered on admin/member panels — independent of business-day override /
// status banners. Effective app date falls back to the calendar when override is off.
$panelId = filament()->getCurrentPanel()?->getId();
$fundName = PublicPageSettings::fundName(tenant('name'));
$portalLabel = $panelId === 'member'
    ? __('Member portal')
    : __('Admin portal');
$todayLabel = BusinessDay::today()->toFormattedDateString();
$isOverridden = BusinessDay::isOverridden();
$showImpersonation = session()->has('impersonator_user_id');
$impersonation = $showImpersonation ? app(ImpersonationService::class) : null;
$impersonatedName = $showImpersonation
    ? (auth('tenant')->user()?->name ?: __('Member'))
    : null;
@endphp

<footer
    @class([
        'ff-portal-bottom-bar',
        'ff-portal-bottom-bar--impersonating' => $showImpersonation,
    ])
    role="contentinfo"
    data-panel="{{ $panelId }}"
    data-business-day-override="{{ $isOverridden ? '1' : '0' }}"
>
    <div class="ff-portal-bottom-bar__accent" aria-hidden="true"></div>
    <div class="ff-portal-bottom-bar__inner">
        <div class="ff-portal-bottom-bar__start">
            <span class="ff-portal-bottom-bar__fund" title="{{ $fundName }}">{{ $fundName }}</span>
            <span class="ff-portal-bottom-bar__divider" aria-hidden="true"></span>
            <span class="ff-portal-bottom-bar__portal">{{ $portalLabel }}</span>
        </div>

        @if ($showImpersonation && $impersonation)
            <div
                class="ff-portal-bottom-bar__center ff-status-footer-banner--impersonation"
                role="status"
                aria-live="polite"
            >
                <span class="ff-portal-bottom-bar__impersonation-dot" aria-hidden="true"></span>
                <span class="ff-portal-bottom-bar__impersonation-text">
                    {{ __('Impersonating: :name', ['name' => $impersonatedName]) }}
                </span>
                <form
                    method="post"
                    action="{{ route('tenant.member.impersonation.stop') }}"
                    class="ff-portal-bottom-bar__impersonation-form"
                >
                    @csrf
                    <button type="submit" class="ff-portal-bottom-bar__impersonation-action">
                        {{ $impersonation->returnActionLabel() }}
                    </button>
                </form>
            </div>
        @endif

        <div class="ff-portal-bottom-bar__end">
            <span
                @class([
    'ff-portal-bottom-bar__date',
    'ff-portal-bottom-bar__date--override' => $isOverridden,
])
                title="{{ $isOverridden ? __('Business day override active') : __('Today') }}"
            >
                {{ $todayLabel }}
            </span>
        </div>
    </div>
</footer>
