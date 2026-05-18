@php
    $fundName = \App\Support\PublicPageSettings::fundName(tenant('name'));
@endphp

@if (filled($fundName))
    <span class="ff-member-topbar-fund-name" title="{{ $fundName }}">
        {{ $fundName }}
    </span>
@endif