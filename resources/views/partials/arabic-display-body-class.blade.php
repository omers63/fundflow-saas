@php
    use App\Support\ArabicDisplaySettings;
@endphp
@if (ArabicDisplaySettings::enhancedNameStyle())
    <script>
        document.documentElement.classList.add('ff-arabic-enhanced-names');
    </script>
@endif