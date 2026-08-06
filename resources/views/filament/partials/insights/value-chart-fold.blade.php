@props([
    'section',
    'title',
    'hint' => null,
    'chart' => null,
    'charts' => null,
])

{{--
    Lazy value chart fold. Parent Livewire component must implement unfoldSection / isSectionUnfolded.
    Pass either :chart="single" or :charts="[...]" — charts are ONLY resolved when the parent
    blade evaluates them inside @if ($this->isSectionUnfolded(...)), so callers should compute
    in that branch.
--}}
<div class="ff-value-chart-fold">
    <x-ff-lazy-fold
        :section="$section"
        :unfolded="$this->isSectionUnfolded($section)"
        :title="$title"
        :hint="$hint"
    >
        @if ($this->isSectionUnfolded($section))
            <div @class([
                'grid grid-cols-1 gap-3',
                'lg:grid-cols-2' => is_array($charts) && count($charts) > 1,
            ])>
                @if (is_array($charts))
                    @foreach ($charts as $item)
                        @include('filament.partials.insights.value-chart', ['chart' => $item])
                    @endforeach
                @elseif (is_array($chart))
                    @include('filament.partials.insights.value-chart', ['chart' => $chart])
                @endif
            </div>
        @endif
    </x-ff-lazy-fold>
</div>
