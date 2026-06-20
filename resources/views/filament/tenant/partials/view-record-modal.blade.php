@php
    /** @var array<int, array<string, mixed>> $sections */
    $chipClasses = [
        'green' => 'ff-chip-green',
        'amber' => 'ff-chip-amber',
        'red' => 'ff-chip-red',
        'blue' => 'ff-chip-blue',
        'sky' => 'ff-chip-sky',
        'gray' => 'ff-chip-gray',
    ];
@endphp

<div class="ff-tenant-record-modal">
    @foreach ($sections as $section)
        @if (!empty($section['hero']))
            @php
                $hero = $section['hero'];
                $heroType = $hero['type'] ?? null;
            @endphp
            <div class="ff-tenant-record-modal__hero">
                <div class="ff-tenant-record-modal__hero-main">
                    @if (filled($hero['label'] ?? null))
                        <p class="ff-tenant-record-modal__hero-label">{{ $hero['label'] }}</p>
                    @endif
                    @if (filled($hero['amount'] ?? null))
                        <p @class([
                            'ff-tenant-record-modal__hero-amount',
                            'ff-tenant-record-modal__hero-amount--credit' => $heroType === 'credit',
                            'ff-tenant-record-modal__hero-amount--debit' => $heroType === 'debit',
                        ])>
                            {{ $hero['amount'] }}
                        </p>
                    @endif
                    @if (filled($hero['subtitle'] ?? null))
                        <p class="ff-tenant-record-modal__hero-subtitle">{{ $hero['subtitle'] }}</p>
                    @endif
                </div>
                <div class="ff-tenant-record-modal__hero-badges">
                    @if (filled($hero['chip'] ?? null))
                        <span @class(['ff-chip', $chipClasses[$hero['chipVariant'] ?? 'gray'] ?? 'ff-chip-gray'])>{{ $hero['chip'] }}</span>
                    @endif
                    @if (filled($hero['chipSecondary'] ?? null))
                        <span @class(['ff-chip', $chipClasses[$hero['chipSecondaryVariant'] ?? 'gray'] ?? 'ff-chip-gray'])>{{ $hero['chipSecondary'] }}</span>
                    @endif
                </div>
            </div>
        @elseif (!empty($section['items']))
            <div class="ff-admin-panel ff-tenant-record-modal__section">
                @if (filled($section['title'] ?? null))
                    <div class="ff-tenant-record-modal__panel-head">
                        <h4 class="ff-tenant-record-modal__panel-title">{{ $section['title'] }}</h4>
                    </div>
                @endif
                <div @class([
                    'ff-tenant-detail-grid',
                    'ff-tenant-detail-grid--3col' => ($section['columns'] ?? 2) === 3,
                ])>
                    @foreach ($section['items'] as $item)
                        <div class="ff-tenant-detail-grid__item">
                            <dt class="ff-tenant-detail-grid__label">{{ $item['label'] }}</dt>
                            <dd class="ff-tenant-detail-grid__value">{{ $item['value'] }}</dd>
                        </div>
                    @endforeach
                </div>
            </div>
        @elseif (!empty($section['prose']))
            <div class="ff-admin-panel ff-tenant-record-modal__section">
                @if (filled($section['title'] ?? null))
                    <div class="ff-tenant-record-modal__panel-head">
                        <h4 class="ff-tenant-record-modal__panel-title">{{ $section['title'] }}</h4>
                    </div>
                @endif
                <p class="ff-tenant-record-modal__prose">{{ $section['prose'] }}</p>
            </div>
        @elseif (!empty($section['html'] ?? null))
            <div class="ff-admin-panel ff-tenant-record-modal__section">
                @if (filled($section['title'] ?? null))
                    <div class="ff-tenant-record-modal__panel-head">
                        <h4 class="ff-tenant-record-modal__panel-title">{{ $section['title'] }}</h4>
                    </div>
                @endif
                <div class="ff-tenant-record-modal__html px-4 py-3">{!! $section['html'] !!}</div>
            </div>
        @elseif (!empty($section['view'] ?? null))
            <div class="ff-admin-panel ff-tenant-record-modal__section">
                @if (filled($section['title'] ?? null))
                    <div class="ff-tenant-record-modal__panel-head">
                        <h4 class="ff-tenant-record-modal__panel-title">{{ $section['title'] }}</h4>
                    </div>
                @endif
                @include($section['view'], $section['viewData'] ?? [])
            </div>
        @endif
    @endforeach
</div>