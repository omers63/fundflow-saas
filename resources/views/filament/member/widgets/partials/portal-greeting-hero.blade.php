@php
        $g = $greeting;
        $currency = $currency ?? null;
    $tone = $g['card_tone'] ?? ($g['highlight_tone'] ?? 'emerald');
@endphp

<div @class([
    'ff-member-greeting ff-member-dashboard-hero',
    'ff-member-greeting--tone-amber' => $tone === 'amber',
    'ff-member-greeting--tone-rose' => in_array($tone, ['rose', 'danger'], true),
    'ff-member-greeting--tone-emerald' => in_array($tone, ['success', 'emerald'], true),
])>
    <div class="ff-member-greeting__shape ff-member-greeting__shape--one" aria-hidden="true"></div>
    <div class="ff-member-greeting__shape ff-member-greeting__shape--two" aria-hidden="true"></div>
    <div class="ff-member-greeting__glow" aria-hidden="true"></div>

    <div class="ff-member-greeting__main">
        <div class="ff-member-greeting__identity">
            <a href="{{ $g['profile_url'] }}" class="ff-member-greeting__avatar" title="{{ __('My profile') }}">
                @if (filled($g['avatar_url'] ?? null))
                    <img src="{{ $g['avatar_url'] }}" alt="{{ $g['name'] }}" loading="lazy" decoding="async"
                        class="ff-member-greeting__avatar-image">
                @else
                    <span class="ff-member-greeting__avatar-initials" aria-hidden="true">{{ $g['initials'] }}</span>
                @endif
            </a>

            <div class="ff-member-greeting__copy">
                <p class="ff-member-greeting__date">{{ $g['date'] }}</p>
                <h2 class="ff-member-greeting__title">
                    {{ $g['period_label'] }},
                    <x-arabic-text :text="$g['name']" />
                </h2>
                <p class="ff-member-greeting__fund">{{ $g['fund_name'] }}</p>
                @if (filled($g['highlight_title'] ?? null))
                    <p class="ff-member-greeting__highlight">{{ $g['highlight_title'] }}</p>
                @endif
                <p class="ff-member-greeting__subtitle">{{ $g['subtitle'] }}</p>
                <div class="ff-member-greeting__meta">
                    <span class="ff-member-greeting__number">{{ $g['member_number'] }}</span>
                    <span class="ff-member-greeting__status">{{ $g['status_label'] }}</span>
                    @if (filled($g['joined_label'] ?? null))
                        <span class="ff-member-greeting__joined">{{ $g['joined_label'] }}</span>
                    @endif
                </div>
                @if (filled($g['highlight_cta_url'] ?? null))
                    <a href="{{ $g['highlight_cta_url'] }}" wire:navigate class="ff-member-greeting__cta">
                        {{ $g['highlight_cta_label'] }} →
                    </a>
                @endif
            </div>
        </div>

        <div class="ff-member-greeting__balances">
            @foreach ($g['balances'] as $balance)
                <a href="{{ $balance['url'] }}" wire:navigate @class([
                    'ff-member-greeting__balance',
                    'ff-member-greeting__balance--negative' => $balance['negative'] ?? false,
                ])
                    title="{{ $balance['full'] }}">
                    <div class="ff-member-greeting__balance-head">
                        <x-dynamic-component :component="$balance['icon']" class="ff-member-greeting__balance-icon" />
                        <span class="ff-member-greeting__balance-label">{{ $balance['label'] }}</span>
                    </div>
                    <p class="ff-member-greeting__balance-amount">
                        @if (isset($balance['amount_value']))
                            <x-member::amount :value="$balance['amount_value']" :currency="$currency" compact />
                        @else
                            {{ $balance['amount'] }}
                        @endif
                    </p>
                </a>
            @endforeach
        </div>
    </div>

    @if (count($g['spotlights'] ?? []) > 0)
        <ul class="ff-member-greeting__spotlights">
            @foreach ($g['spotlights'] as $spotlight)
                <li>
                    <a href="{{ $spotlight['url'] }}" wire:navigate class="ff-member-greeting__spotlight">
                        <x-dynamic-component :component="$spotlight['icon']" class="ff-member-greeting__spotlight-icon" />
                        <span class="ff-member-greeting__spotlight-copy">
                            <span class="ff-member-greeting__spotlight-label">{{ $spotlight['label'] }}</span>
                            <span class="ff-member-greeting__spotlight-value">{{ $spotlight['value'] }}</span>
                            @if (filled($spotlight['sub'] ?? null))
                                <span class="ff-member-greeting__spotlight-sub">{{ $spotlight['sub'] }}</span>
                            @endif
                        </span>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif

    @if (count($g['pills'] ?? []) > 0)
        <ul class="ff-member-greeting__pills">
            @foreach ($g['pills'] as $pill)
                <li>
                    @if (filled($pill['url'] ?? null))
                        <a href="{{ $pill['url'] }}" wire:navigate @class([
                            'ff-member-greeting__pill',
                            'ff-member-greeting__pill--' . ($pill['tone'] ?? 'default') => filled($pill['tone'] ?? null),
                        ])>
                            <x-dynamic-component :component="$pill['icon']" class="ff-member-greeting__pill-icon" />
                            {{ $pill['label'] }}
                        </a>
                    @else
                        <span class="ff-member-greeting__pill ff-member-greeting__pill--static">
                            <x-dynamic-component :component="$pill['icon']" class="ff-member-greeting__pill-icon" />
                            {{ $pill['label'] }}
                        </span>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</div>
