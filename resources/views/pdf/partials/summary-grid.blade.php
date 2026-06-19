@php
    $isArabic = $isArabic ?? app()->getLocale() === 'ar';
@endphp
<table class="summary-grid summary-grid--{{ $isArabic ? 'ar' : 'en' }}" dir="ltr">
    @foreach ($rows as $row)
        <tr>
            @if ($isArabic)
                <td class="summary-grid__value">{!! $row['html'] !!}</td>
                <td class="summary-grid__label">{{ $row['label'] }}</td>
            @else
                <td class="summary-grid__label">{{ $row['label'] }}</td>
                <td class="summary-grid__value">{!! $row['html'] !!}</td>
            @endif
        </tr>
    @endforeach
</table>