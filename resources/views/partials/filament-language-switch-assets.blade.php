{{-- Minimal Filament assets so the language-switch dropdown works on non-panel pages. --}}
{!! \Filament\Support\Facades\FilamentAsset::renderStyles() !!}
{!! \Filament\Support\Facades\FilamentAsset::renderScripts(withCore: true) !!}