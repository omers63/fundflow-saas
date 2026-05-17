<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\AppLocale;
use BezhanSalleh\LanguageSwitch\Events\LocaleChanged;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LocaleSwitchController extends Controller
{
    public function __invoke(Request $request, string $locale): RedirectResponse
    {
        abort_unless(AppLocale::isSupported($locale), 404);

        $request->session()->put('locale', $locale);

        cookie()->queue(cookie()->forever('filament_language_switch_locale', $locale));

        event(new LocaleChanged($locale));

        return redirect()->back(302, [], route('tenant.home'));
    }
}
