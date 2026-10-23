<x-filament-widgets::widget>
    @include('filament.partials.insights.member-lifetime-chart', [
        'rows' => $rows,
        'currency' => null,
        'title' => __('Fund contributions & repayments — lifetime'),
    ])
</x-filament-widgets::widget>
