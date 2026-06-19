@php
    $url = $url ?? null;
    $isImage = $isImage ?? false;
@endphp

@if (filled($url))
    <div class="ff-member-record-modal__receipt">
        @if ($isImage)
            <a href="{{ $url }}" target="_blank" rel="noopener noreferrer" class="block">
                <img src="{{ $url }}" alt="{{ __('Receipt') }}" class="ff-member-record-modal__receipt-image" />
            </a>
        @else
            <a href="{{ $url }}" target="_blank" rel="noopener noreferrer" class="ff-member-record-modal__receipt-link">
                {{ __('Download attachment') }}
            </a>
        @endif
    </div>
@endif