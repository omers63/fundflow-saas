<?php

namespace App\Filament\Concerns;

use App\Filament\Member\Support\MemberNavigation;
use App\Filament\Tenant\Support\TenantNavigation;
use App\Support\Lang;
use Illuminate\Support\Str;
use UnitEnum;

use function Filament\Support\get_model_label;
use function Filament\Support\locale_has_pluralization;

trait TranslatesFilamentNavigationLabels
{
    public static function getModelLabel(): string
    {
        return Lang::formatUiLabel(__(static::untranslatedModelLabel()));
    }

    public static function getPluralModelLabel(): string
    {
        return Lang::formatUiLabel(__(static::untranslatedPluralModelLabel()));
    }

    public static function getNavigationLabel(): string
    {
        return Lang::formatUiLabel(__(parent::getNavigationLabel()));
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        $group = parent::getNavigationGroup();

        if (! is_string($group)) {
            return $group;
        }

        if (MemberNavigation::isGroupKey($group) || TenantNavigation::isGroupKey($group)) {
            return $group;
        }

        return Lang::formatUiLabel(__($group));
    }

    /**
     * English (or configured) singular before translation. Avoids Filament
     * calling Str::plural() on an already-translated Arabic label (→ "مساهمةs").
     */
    protected static function untranslatedModelLabel(): string
    {
        return static::$modelLabel
            ?? static::$label
            ?? get_model_label(static::getModel());
    }

    /**
     * English (or configured) plural before translation.
     */
    protected static function untranslatedPluralModelLabel(): string
    {
        if (filled(static::$pluralModelLabel)) {
            return static::$pluralModelLabel;
        }

        if (filled(static::$pluralLabel)) {
            return static::$pluralLabel;
        }

        $singular = static::untranslatedModelLabel();

        if (locale_has_pluralization()) {
            return Str::plural($singular);
        }

        return $singular;
    }
}
