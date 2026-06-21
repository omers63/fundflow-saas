<?php

declare(strict_types=1);

use App\Support\LoanFundExcessDisposition;
use App\Support\LoanFundingStrategy;

test('member portal user-facing strings have arabic translations', function () {
    $output = shell_exec('php '.base_path('scripts/find-member-missing-ar.php'));

    expect($output)->not->toBeNull();

    preg_match('/Total missing: (\d+)/', (string) $output, $matches);

    expect((int) ($matches[1] ?? -1))->toBe(0);
});

test('loan request wizard funding labels resolve in arabic locale', function () {
    app()->setLocale('ar');

    expect(LoanFundExcessDisposition::options()[LoanFundExcessDisposition::KEEP_IN_FUND])
        ->toBe('إبقاء الرصيد المتبقي في حساب صندوقي')
        ->and(LoanFundingStrategy::options()[LoanFundingStrategy::MEMBER_FUND_TOPUP])
        ->toContain('صندوقي');
});
