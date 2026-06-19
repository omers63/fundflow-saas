<?php

declare(strict_types=1);

/**
 * Guards Filament table definitions for bulk toolbar, group-by, and filters.
 *
 * @see .cursor/rules/tables-toolbar-standards.mdc
 *
 * Row actions: use {@see TableRecordActionGroups::apply()} when configuring record actions.
 * When the list contains only a View action, the row opens it on click and no actions column is shown.
 */
test('filament tables define bulk actions, group-by, and filters', function (): void {
    $root = dirname(__DIR__, 2).'/app/Filament';

    $paths = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    );

    $violations = [];

    foreach ($paths as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $path = $file->getPathname();
        $contents = file_get_contents($path);

        if ($contents === false) {
            continue;
        }

        $isTableDefinition = str_contains($contents, 'function table(Table')
            || (str_contains($contents, 'static function configure(Table') && str_contains($contents, '->columns('));

        if (! $isTableDefinition || ! str_contains($contents, '->columns(')) {
            continue;
        }

        if (str_contains($path, '/Support/ViewActions/')) {
            continue;
        }

        $relative = str_replace(dirname(__DIR__, 2).'/', '', $path);

        if (! str_contains($contents, 'toolbarActions') && ! str_contains($contents, 'TableToolbar::bulkGroup')) {
            $violations[] = "{$relative}: missing toolbarActions / bulk group";
        }

        $hasGrouping = str_contains($contents, 'TableGrouping::apply')
            || str_contains($contents, '->groups(')
            || str_contains($contents, 'ViewAccountTransactionAction::configure')
            || str_contains($contents, 'ViewBankTransactionAction::configure')
            || str_contains($contents, 'ViewFundPostingAction::configure');

        if (! $hasGrouping) {
            $violations[] = "{$relative}: missing TableGrouping::apply / groups";
        }

        if (! str_contains($contents, '->filters(')) {
            $violations[] = "{$relative}: missing ->filters()";
        }
    }

    expect($violations)->toBeEmpty(implode("\n", $violations));
});
