<?php

declare(strict_types=1);

it('forces pool trend charts to left-to-right regardless of UI locale', function (): void {
    $sparkline = file_get_contents(resource_path('views/filament/tenant/widgets/tenant-dashboard.blade.php'));
    $flow = file_get_contents(resource_path('views/filament/tenant/widgets/partials/pool-flow-trend.blade.php'));

    expect($sparkline)
        ->toContain('30-day pool trend')
        ->toContain('<div class="relative h-14 sm:h-16" dir="ltr">')
        ->and($flow)
        ->toContain('12-cycle pool trend')
        ->toContain('dir="ltr">')
        ->toContain('<svg class="pointer-events-none absolute inset-0 z-10 h-full w-full"');

    $ltrAt = strpos($flow, 'dir="ltr"');
    $svgAt = strpos($flow, '<svg class="pointer-events-none absolute inset-0 z-10 h-full w-full"');

    expect($ltrAt)->toBeInt()
        ->and($svgAt)->toBeInt()
        ->and($svgAt)->toBeGreaterThan($ltrAt)
        ->and($svgAt - $ltrAt)->toBeLessThan(500);
});
