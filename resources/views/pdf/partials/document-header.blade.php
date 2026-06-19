@php
    $isArabic = $isArabic ?? app()->getLocale() === 'ar';
    $logoDataUri = $logoDataUri ?? null;
@endphp
<div class="doc-header">
    <table class="doc-header__layout">
        <tr>
            @if ($isArabic)
                <td>
                    <h1 class="doc-header__brand">{{ $brand }}</h1>
                    @if (!empty($subtitle))
                        <p class="doc-header__subtitle">{{ $subtitle }}</p>
                    @endif
                    @if (!empty($meta))
                        <div class="doc-header__meta">{{ $meta }}</div>
                    @endif
                </td>
                @if ($logoDataUri)
                    <td class="doc-header__logo-cell" style="text-align: left;">
                        <img src="{{ $logoDataUri }}" alt="" class="doc-header__logo">
                    </td>
                @endif
            @else
                @if ($logoDataUri)
                    <td class="doc-header__logo-cell">
                        <img src="{{ $logoDataUri }}" alt="" class="doc-header__logo">
                    </td>
                @endif
                <td>
                    <h1 class="doc-header__brand">{{ $brand }}</h1>
                    @if (!empty($subtitle))
                        <p class="doc-header__subtitle">{{ $subtitle }}</p>
                    @endif
                    @if (!empty($meta))
                        <div class="doc-header__meta">{{ $meta }}</div>
                    @endif
                </td>
            @endif
        </tr>
    </table>
</div>