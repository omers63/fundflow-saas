<?php

declare(strict_types=1);

$ar = json_decode((string) file_get_contents(__DIR__ . '/../lang/ar.json'), true, 512, JSON_THROW_ON_ERROR);

$paths = array_merge(
    glob(__DIR__ . '/../resources/views/filament/tenant/pages/audit-system.blade.php') ?: [],
    glob(__DIR__ . '/../resources/views/filament/tenant/pages/partials/*migration*') ?: [],
    glob(__DIR__ . '/../resources/views/filament/tenant/pages/partials/*fiscal*') ?: [],
    glob(__DIR__ . '/../resources/views/filament/tenant/pages/partials/*maintenance*') ?: [],
    glob(__DIR__ . '/../resources/views/filament/tenant/pages/embedded/*') ?: [],
    glob(__DIR__ . '/../resources/views/filament/tenant/partials/audit-system/*') ?: [],
    glob(__DIR__ . '/../resources/views/filament/tenant/partials/legacy-migration*') ?: [],
    glob(__DIR__ . '/../app/Filament/Tenant/Pages/AuditSystemPage.php') ?: [],
    glob(__DIR__ . '/../app/Filament/Tenant/Pages/LegacyMigrationPage.php') ?: [],
    glob(__DIR__ . '/../app/Filament/Tenant/Pages/FiscalYearClosePage.php') ?: [],
    glob(__DIR__ . '/../app/Filament/Tenant/Pages/SystemMaintenancePage.php') ?: [],
    glob(__DIR__ . '/../app/Filament/Tenant/Support/AuditSystemTabRegistry.php') ?: [],
    glob(__DIR__ . '/../app/Filament/Tenant/Concerns/InteractsWithJobsTable.php') ?: [],
    glob(__DIR__ . '/../app/Filament/Tenant/Resources/FundAuditLogs/Tables/FundAuditLogsTable.php') ?: [],
    glob(__DIR__ . '/../app/Filament/Tenant/Resources/NotificationLogs/Tables/NotificationLogsTable.php') ?: [],
    glob(__DIR__ . '/../app/Filament/Tenant/Widgets/DatabaseBackupOverviewWidget.php') ?: [],
    glob(__DIR__ . '/../app/Filament/Tenant/Widgets/DatabaseBackupsTableWidget.php') ?: [],
    glob(__DIR__ . '/../app/Support/LegacyMigrationSampleCsv.php') ?: [],
);

$missing = [];

foreach ($paths as $path) {
    $content = (string) file_get_contents($path);

    if (preg_match_all("/__\\(['\"]([^'\"\\n]{2,300})['\"]/", $content, $matches)) {
        foreach ($matches[1] as $key) {
            if (!array_key_exists($key, $ar)) {
                $missing[$key] = true;
            }
        }
    }
}

ksort($missing);

echo count($missing) . " missing keys\n";

foreach (array_keys($missing) as $key) {
    echo $key . "\n";
}
