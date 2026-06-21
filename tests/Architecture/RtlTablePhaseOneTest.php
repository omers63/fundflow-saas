<?php

declare(strict_types=1);

test('rtl table phase 1 toolbar styles are defined', function () {
    $css = file_get_contents(dirname(__DIR__, 2).'/resources/css/filament/rtl-table-phases.css');

    expect($css)
        ->toContain('Phase 1: Table header toolbar')
        ->toContain("html[dir='rtl'] .fi-body .fi-ta-ctn .fi-ta-header-toolbar")
        ->toContain('.fi-ta-header-toolbar > .fi-ta-actions')
        ->toContain('.fi-ta-header-toolbar .fi-ta-search-field');
});
