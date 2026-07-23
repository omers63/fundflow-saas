@php
    $primary = $primaryColor ?? '#0f766e';
    $isRtl = (bool) ($isRtl ?? false);
    $locale = $locale ?? 'en';
@endphp
    <x-mail::message>
        @if (filled($logoPath ?? null))
            <div style="text-align:center;margin-bottom:16px;">
                <img src="{{ $message->embed(storage_path('app/public/' . $logoPath)) }}" alt="" style="max-height:48px;">
            </div>
        @endif
    
        <div @if ($isRtl) dir="rtl" lang="{{ $locale }}" @else dir="ltr" lang="{{ $locale }}" @endif
            style="border-top:3px solid {{ $primary }};padding-top:16px;@if ($isRtl) direction:rtl;text-align:right;@endif">
            {!! $bodyHtml !!}
    
            @isset($actionUrl)
                @if (filled($actionUrl))
                    <div style="@if ($isRtl) text-align:right; @else text-align:left; @endif margin-top:16px;">
                        <x-mail::button :url="$actionUrl" color="primary">
                            {{ $actionLabel ?? __('Open') }}
                        </x-mail::button>
                    </div>
                @endif
            @endisset
    
            <p style="color:#6b7280;font-size:12px;margin-top:24px;@if ($isRtl) direction:rtl;text-align:right;@endif">
                {{ $footer }}</p>
        </div>
</x-mail::message>
