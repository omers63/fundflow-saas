<?php

declare(strict_types=1);

$roots = [
    __DIR__.'/../app/Filament/Tenant/Pages/AuditSystemPage.php',
    __DIR__.'/../app/Filament/Tenant/Pages/Settings.php',
    __DIR__.'/../app/Filament/Tenant/Pages/ReportsPage.php',
    __DIR__.'/../app/Filament/Tenant/Pages/Dashboard.php',
    __DIR__.'/../app/Filament/Tenant/Pages/SystemMaintenancePage.php',
    __DIR__.'/../app/Filament/Tenant/Pages/LegacyMigrationPage.php',
    __DIR__.'/../app/Filament/Tenant/Pages/FiscalYearClosePage.php',
    __DIR__.'/../app/Filament/Tenant/Pages/JobsPage.php',
    __DIR__.'/../app/Filament/Tenant/Support/SettingsTabRegistry.php',
    __DIR__.'/../resources/views/filament/tenant/pages/audit-system.blade.php',
    __DIR__.'/../resources/views/filament/tenant/pages/settings.blade.php',
    __DIR__.'/../resources/views/filament/tenant/pages/reports.blade.php',
    __DIR__.'/../resources/views/filament/tenant/widgets/tenant-dashboard.blade.php',
    __DIR__.'/../app/Services/TenantDashboardService.php',
];

$globPaths = [
    __DIR__.'/../app/Filament/Tenant/Resources/FundAuditLogs/**/*.php',
    __DIR__.'/../app/Filament/Tenant/Resources/NotificationLogs/**/*.php',
];

foreach ($globPaths as $pattern) {
    $roots = array_merge($roots, glob($pattern) ?: []);
}

/** @var array<string, string> $ar */
$ar = json_decode((string) file_get_contents(__DIR__.'/../lang/ar.json'), true, 512, JSON_THROW_ON_ERROR);

$keys = [];

foreach ($roots as $path) {
    if (! is_file($path)) {
        continue;
    }

    $content = (string) file_get_contents($path);

    if (preg_match_all("/__\\(['\"]([^'\"\\n]{2,200})['\"]/", $content, $matches)) {
        foreach ($matches[1] as $key) {
            $keys[$key] = true;
        }
    }

    if (preg_match_all("/Lang::ui\\(['\"]([^'\"\\n]{2,200})['\"]/", $content, $uiMatches)) {
        foreach ($uiMatches[1] as $key) {
            $keys[$key] = true;
        }
    }
}

$missing = [];

foreach (array_keys($keys) as $key) {
    if (! array_key_exists($key, $ar)) {
        $missing[] = $key;
    }
}

sort($missing);

echo count($missing)." missing keys\n";

foreach ($missing as $key) {
    echo $key."\n";
}
