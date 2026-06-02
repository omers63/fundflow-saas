@php
    use App\Support\ArabicDisplaySettings;
@endphp
<link rel="preconnect" href="https://fonts.bunny.net">
<link href="https://fonts.bunny.net/css?family={{ ArabicDisplaySettings::bunnyFontsFamilyParam() }}&display=swap"
    rel="stylesheet" />
<style>
    :root {
        --ff-font-arabic:
            {!! ArabicDisplaySettings::fontFamilyCss() !!}
        ;
    }
</style>