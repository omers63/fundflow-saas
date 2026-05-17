@php
    $targetLocale = app()->getLocale() === 'ar' ? 'en' : 'ar';
    $label = app()->getLocale() === 'ar' ? __('English') : __('العربية');
@endphp

<a href="{{ route('tenant.locale.switch', ['locale' => $targetLocale]) }}" {{ $attributes->class([
    'text-sm font-medium text-gray-600 transition-colors hover:text-gray-900 px-2 py-2',
]) }}>
    {{ $label }}
</a>