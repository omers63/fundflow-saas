<?php

declare(strict_types=1);

/**
 * Virtual Filament columns (state / getStateUsing) inherit global sortable() and
 * searchable() and produce SQL on non-existent columns unless overridden.
 *
 * @see app/Providers/AppServiceProvider.php Column::configureUsing
 */
test('virtual filament table columns override default SQL sorting', function (): void {
    $root = dirname(__DIR__, 2) . '/app/Filament';

    /** Column names that map to real DB attributes even when display uses state(). */
    $dbBackedNames = [
        'description',
        'balance',
        'monthly_contribution_amount',
        'read_at',
        'notified_at',
        'posted_at',
        'investment_id',
        'expense_id',
        'outstanding',
        'loan_outstanding',
        // Sorted on a later statement (see LoanQueueTable::projectedColumn).
        'projected_wait',
        'account_scope',
    ];

    $paths = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    );

    $violations = [];
    $columnMakePattern = '/(?P<class>(?:Text|Icon|Image|Color|Checkbox|Select|Toggle|View|Badge)Column)::make\((?P<name>[^)]+)\)/';

    foreach ($paths as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $path = $file->getPathname();
        $contents = file_get_contents($path);

        if ($contents === false || !preg_match_all($columnMakePattern, $contents, $matches, PREG_OFFSET_CAPTURE)) {
            continue;
        }

        $relative = str_replace(dirname(__DIR__, 2) . '/', '', $path);
        $count = count($matches[0]);

        for ($i = 0; $i < $count; $i++) {
            $start = $matches[0][$i][1] + strlen($matches[0][$i][0]);
            $end = $i + 1 < $count ? $matches[0][$i + 1][1] : strlen($contents);
            $body = substr($contents, $start, $end - $start);

            // Stop at the next table configuration section so Action/form state() is ignored.
            if (
                preg_match(
                    '/\n\s*->(?:filters|recordActions|toolbarActions|headerActions|bulkActions|defaultSort|modifyQueryUsing|paginated|heading|recordUrl|recordAction|groups)\(/',
                    $body,
                    $cut,
                    PREG_OFFSET_CAPTURE,
                )
            ) {
                $body = substr($body, 0, $cut[0][1]);
            }

            if (preg_match('/\b(?:Action|BulkAction)::make\(/', $body, $cut, PREG_OFFSET_CAPTURE)) {
                $body = substr($body, 0, $cut[0][1]);
            }

            $hasVirtualState = str_contains($body, '->state(') || str_contains($body, '->getStateUsing(');

            if (!$hasVirtualState) {
                continue;
            }

            $rawName = trim($matches['name'][$i][0]);
            $name = trim($rawName, " \t\n\r\0\x0B\"'");

            if (str_starts_with($rawName, '$') || in_array($name, $dbBackedNames, true)) {
                continue;
            }

            if (str_contains($name, '.')) {
                continue;
            }

            $hasSortOverride = (bool) preg_match('/->sortable\s*\(\s*false\s*\)/', $body)
                || (bool) preg_match('/->sortable\s*\(\s*query\s*:/', $body)
                || (bool) preg_match('/->sortable\s*\(\s*fn\b/', $body);

            if (!$hasSortOverride) {
                $violations[] = "{$relative} :: {$name} (sortable)";
            }

            $hasSearchOverride = (bool) preg_match('/->searchable\s*\(\s*false\s*\)/', $body)
                || (bool) preg_match('/->searchable\s*\(\s*query\s*:/', $body)
                || (bool) preg_match('/->searchable\s*\(\s*fn\b/', $body);

            if (!$hasSearchOverride) {
                $violations[] = "{$relative} :: {$name} (searchable)";
            }
        }
    }

    expect($violations)->toBeEmpty(
        "Virtual columns must override default SQL sortable/searchable.\n" . implode("\n", $violations),
    );
});
