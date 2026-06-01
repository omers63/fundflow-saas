<?php

namespace App\Filament\Support;

use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Str;

class TabLabelColors
{
    /**
     * @var list<string>
     */
    private const PALETTE = ['primary', 'info', 'success', 'warning', 'danger', 'gray'];

    /**
     * @var array<string, string>
     */
    private const KEY_COLORS = [
        'cash' => 'info',
        'fund' => 'success',
        'loans' => 'warning',
        'all' => 'gray',
        'statements' => 'primary',
        'imports' => 'info',
        'ledger' => 'warning',
        'transactions' => 'info',
        'bank' => 'primary',
        'expense' => 'danger',
        'fees' => 'warning',
        'invest' => 'success',
        'accounts' => 'info',
        'contributions' => 'success',
        'repayments' => 'warning',
        'dependents' => 'primary',
        'household' => 'primary',
        'directmessages' => 'info',
        'messages' => 'info',
        'general' => 'gray',
        'public-page' => 'primary',
        'contributions-settings' => 'success',
        'bank-templates' => 'warning',
        'account' => 'primary',
        'details' => 'info',
        'form-upload' => 'warning',
    ];

    public static function forLabel(string|Htmlable|null $label): string
    {
        if ($label instanceof Htmlable) {
            $label = strip_tags($label->toHtml() ?? '');
        }

        $slug = Str::slug(Str::transliterate((string) $label, strict: true));

        return self::forKey($slug);
    }

    public static function forKey(string $key): string
    {
        $slug = Str::slug(Str::before($key, '::'));

        if (isset(self::KEY_COLORS[$slug])) {
            return self::KEY_COLORS[$slug];
        }

        foreach (self::KEY_COLORS as $needle => $color) {
            if (str_contains($slug, $needle)) {
                return $color;
            }
        }

        $index = abs(crc32($slug)) % count(self::PALETTE);

        return self::PALETTE[$index];
    }
}
