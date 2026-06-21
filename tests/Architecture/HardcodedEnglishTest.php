<?php

declare(strict_types=1);

use Tests\Support\HardcodedEnglishCatalog;

test('tenant and member filament views do not use physical text-left or text-right alignment', function () {
    expect(HardcodedEnglishCatalog::physicalAlignmentFindings())->toBeEmpty();
});
