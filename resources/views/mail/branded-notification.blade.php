@php
    $primary = $primaryColor ?? '#0f766e';
@endphp
<x-mail::message>
@if (filled($logoPath ?? null))
<div style="text-align:center;margin-bottom:16px;">
    <img src="{{ $message->embed(storage_path('app/public/'.$logoPath)) }}" alt="" style="max-height:48px;">
</div>
@endif

<div style="border-top:3px solid {{ $primary }};padding-top:16px;">
{!! $bodyHtml !!}
</div>

@isset($actionUrl)
@if (filled($actionUrl))
<x-mail::button :url="$actionUrl" color="primary">
{{ $actionLabel ?? __('Open') }}
</x-mail::button>
@endif
@endisset

<p style="color:#6b7280;font-size:12px;margin-top:24px;">{{ $footer }}</p>
</x-mail::message>
