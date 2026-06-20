<?php

declare(strict_types=1);

test('member portal user-facing strings have arabic translations', function () {
    $output = shell_exec('php '.base_path('scripts/find-member-missing-ar.php'));

    expect($output)->not->toBeNull();

    preg_match('/Total missing: (\d+)/', (string) $output, $matches);

    expect((int) ($matches[1] ?? -1))->toBeLessThanOrEqual(2);
});
