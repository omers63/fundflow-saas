<?php

declare(strict_types=1);

namespace App\Http\Livewire;

use BezhanSalleh\LanguageSwitch\LanguageSwitch;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class TenantTopbarLanguageSwitch extends Component
{
    public function switchLocale(string $locale): void
    {
        LanguageSwitch::trigger(locale: $locale);

        $this->redirect(request()->header('Referer') ?? url('/'), navigate: false);
    }

    public function render(): View
    {
        return view('filament.tenant.partials.topbar-language-switch');
    }
}
