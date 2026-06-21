<?php

declare(strict_types=1);

$ar = json_decode((string) file_get_contents(__DIR__.'/../lang/ar.json'), true, 512, JSON_THROW_ON_ERROR);

$roots = [
    __DIR__.'/../app/Filament/Member',
    __DIR__.'/../resources/views/filament/member',
    __DIR__.'/../app/Services/MemberPortalInsightsService.php',
    __DIR__.'/../app/Services/MemberContributionInsightsService.php',
    __DIR__.'/../app/Services/MemberCashOutInsightsService.php',
    __DIR__.'/../app/Services/MemberFundPostingInsightsService.php',
    __DIR__.'/../app/Services/MemberGuaranteedLoanInsightsService.php',
    __DIR__.'/../app/Services/MemberPortalAccountsInsightsService.php',
    __DIR__.'/../app/Services/MemberPortalAccountDetailInsightsService.php',
    __DIR__.'/../app/Services/MemberDetailInsightsService.php',
    __DIR__.'/../app/Services/Concerns/EnrichesMemberPortalDashboard.php',
    __DIR__.'/../app/Support/LoanFundingStrategy.php',
    __DIR__.'/../app/Support/LoanFundExcessDisposition.php',
    __DIR__.'/../app/Services/Loans/LoanEligibilityService.php',
    __DIR__.'/../app/Services/Loans/LoanLifecycleService.php',
    __DIR__.'/../lang/en/member_faq.php',
];

$missing = [];
$pattern = '/__\([\'"]([^\'"]+)[\'"]/';

$scan = function (string $path) use (&$scan, &$missing, $ar, $pattern): void {
    if (is_dir($path)) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));
        foreach ($iterator as $file) {
            if ($file->isFile() && preg_match('/\.(php|blade\.php)$/', $file->getFilename())) {
                $scan($file->getPathname());
            }
        }

        return;
    }

    $content = (string) file_get_contents($path);

    if (preg_match_all($pattern, $content, $matches)) {
        foreach ($matches[1] as $key) {
            if (! isset($ar[$key]) && ! isset($missing[$key])) {
                $missing[$key] = $path;
            }
        }
    }
};

foreach ($roots as $root) {
    if (file_exists($root)) {
        $scan($root);
    }
}

ksort($missing);

echo count($missing)." missing keys\n\n";

foreach ($missing as $key => $file) {
    echo $key.' | '.str_replace(__DIR__.'/../', '', $file)."\n";
}
