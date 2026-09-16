<?php

declare(strict_types=1);

use App\Support\Security\TotpService;

test('totp generates and verifies codes within window', function () {
    $totp = new TotpService;
    $secret = $totp->generateSecret();

    $code = $totp->at($secret, (int) floor(time() / 30));

    expect($totp->verify($secret, $code))->toBeTrue()
        ->and($totp->verify($secret, '000000'))->toBeFalse()
        ->and($totp->provisioningUri($secret, 'admin@test.com', 'FundFlow'))
        ->toStartWith('otpauth://totp/');
});
