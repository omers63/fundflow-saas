<?php

declare(strict_types=1);

use App\Filament\Tenant\Resources\Contributions\ContributionResource;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;

test('contribution resource plural label is translated not english-inflected arabic', function () {
    App::setLocale('ar');

    expect(ContributionResource::getModelLabel())->toBe('المساهمة')
        ->and(ContributionResource::getPluralModelLabel())->toBe('المساهمات')
        ->and(ContributionResource::getNavigationLabel())->toBe('المساهمات')
        ->and(ContributionResource::getPluralModelLabel())->not->toContain('s')
        ->and(ContributionResource::getPluralModelLabel())->not->toContain('S');
});

test('filament does not str-plural already-translated arabic contribution singular', function () {
    App::setLocale('ar');

    $broken = Str::plural('المساهمة');

    expect($broken)->toBe('المساهمةs')
        ->and(ContributionResource::getPluralModelLabel())->not->toBe($broken);
});
