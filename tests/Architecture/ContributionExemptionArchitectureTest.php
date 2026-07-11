<?php

declare(strict_types=1);
use App\Support\ContributionExemptionPolicy;

/**
 * Contribution exemption must flow through {@see ContributionExemptionPolicy}.
 *
 * @see docs/contribution-exemption-policy-plan.md
 */
test('contribution exemption does not use legacy SQL scopes', function (): void {
    $root = dirname(__DIR__, 2);
    $forbidden = [
        'notExemptFromContributionsForCycle',
        'scopeNotExemptFromContributionsForCycle',
    ];

    $paths = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root . '/app', FilesystemIterator::SKIP_DOTS),
    );

    $violations = [];

    foreach ($paths as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $contents = file_get_contents($file->getPathname());

        if ($contents === false) {
            continue;
        }

        foreach ($forbidden as $needle) {
            if (str_contains($contents, $needle)) {
                $violations[] = str_replace($root . '/', '', $file->getPathname()) . ": references {$needle}";
            }
        }
    }

    expect($violations)->toBeEmpty(
        "Use ContributionExemptionPolicy via Member::isExemptFromContributions() or ContributionCycleService::pendingMembersQueryForPeriod().\n"
        . implode("\n", $violations),
    );
});
