@php
    /** @var array<int, array<string, mixed>> $sections */
@endphp

<div class="ff-member-record-modal">
    @foreach ($sections as $section)
        @if (!empty($section['hero']))
            @php
                $hero = $section['hero'];
                $heroType = $hero['type'] ?? null;
            @endphp
            <div class="ff-member-record-modal__hero">
                <div class="ff-member-record-modal__hero-main">
                    @if (filled($hero['label'] ?? null))
                        <p class="ff-member-record-modal__hero-label">{{ $hero['label'] }}</p>
                    @endif
                    @if (filled($hero['amount'] ?? null))
                        <p @class([
                            'ff-member-record-modal__hero-amount',
                            'ff-member-record-modal__hero-amount--credit' => $heroType === 'credit',
                            'ff-member-record-modal__hero-amount--debit' => $heroType === 'debit',
                        ])>
                            {!! \App\Filament\Support\MoneyDisplay::markupForDisplay($hero['amount']) !!}
                        </p>
                    @endif
                    @if (filled($hero['subtitle'] ?? null))
                        <p class="ff-member-record-modal__hero-subtitle">{{ $hero['subtitle'] }}</p>
                    @endif
                </div>
                <div class="ff-member-record-modal__hero-badges">
                    @if (filled($hero['chip'] ?? null))
                        <x-member::chip :variant="$hero['chipVariant'] ?? 'gray'">{{ $hero['chip'] }}</x-member::chip>
                    @endif
                    @if (filled($hero['chipSecondary'] ?? null))
                        <x-member::chip :variant="$hero['chipSecondaryVariant'] ?? 'gray'">{{ $hero['chipSecondary'] }}</x-member::chip>
                    @endif
                </div>
            </div>
        @elseif (!empty($section['items']))
            <x-member::panel :title="$section['title'] ?? null" class="ff-member-record-modal__section">
                <x-member::detail-grid :items="$section['items']" :class="($section['columns'] ?? 2) === 3 ? 'ff-member-detail-grid--3col' : ''" />
            </x-member::panel>
        @elseif (!empty($section['prose']))
            <x-member::panel :title="$section['title'] ?? null" class="ff-member-record-modal__section">
                <p class="ff-member-record-modal__prose">{{ $section['prose'] }}</p>
            </x-member::panel>
        @elseif (!empty($section['view'] ?? null))
            <x-member::panel :title="$section['title'] ?? null" class="ff-member-record-modal__section">
                @include($section['view'], $section['viewData'] ?? [])
            </x-member::panel>
        @endif
    @endforeach
</div>