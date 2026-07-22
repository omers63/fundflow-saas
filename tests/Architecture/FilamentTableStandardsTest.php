<?php

declare(strict_types=1);

/**
 * Guards Filament table definitions for bulk toolbar, group-by, and filters.
 *
 * @see .cursor/rules/tables-toolbar-standards.mdc
 *
 * Row actions: use {@see TableRecordActionGroups::apply()} when configuring record actions.
 * When the list contains only a View or Edit action, the row opens it on click and no actions column is shown.
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

test('filament tables with a member name column also define a member number column', function (): void {
    $root = dirname(__DIR__, 2).'/app/Filament';

    $nameMarkers = [
        "TextColumn::make('member.name')",
        'TextColumn::make("member.name")',
        "TextColumn::make('loan.member.name')",
        "TextColumn::make('member_name')",
        "TextColumn::make('guarantor.name')",
        "TextColumn::make('loan.guarantor.name')",
        'MemberTableColumns::relationName(',
        'MemberTableColumns::name(',
    ];

    $numberMarkers = [
        'member.member_number',
        'MemberTableColumns::relationNumber',
        'MemberTableColumns::number(',
        'loan.member.member_number',
        'MemberTableColumns::loanMemberNumber',
        'guarantor.member_number',
        'loan.guarantor.member_number',
        'MemberTableColumns::guarantorNumber',
        "TextColumn::make('member_number')",
        'requester.member_number',
    ];

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

        if (! str_contains($contents, '->columns(') && ! str_contains($contents, 'function columns')) {
            continue;
        }

        $hasName = false;

        foreach ($nameMarkers as $marker) {
            if (str_contains($contents, $marker)) {
                $hasName = true;

                break;
            }
        }

        if (! $hasName) {
            continue;
        }

        $hasNumber = false;

        foreach ($numberMarkers as $marker) {
            if (str_contains($contents, $marker)) {
                $hasNumber = true;

                break;
            }
        }

        if (! $hasNumber) {
            $violations[] = str_replace(dirname(__DIR__, 2).'/', '', $path);
        }
    }

    expect($violations)->toBeEmpty(
        "Tables with a member name column must also define a member number column:\n".implode("\n", $violations),
    );
});
