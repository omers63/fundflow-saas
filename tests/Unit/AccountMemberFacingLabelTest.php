<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use Tests\TestCase;

uses(TestCase::class);

test('member cash and fund accounts use member facing labels', function (): void {
    app()->setLocale('en');

    expect((new Account(['type' => 'cash', 'name' => 'Member - Cash', 'is_master' => false]))->memberFacingLabel())
        ->toBe('Cash account')
        ->and((new Account(['type' => 'fund', 'name' => 'Member - Fund', 'is_master' => false]))->memberFacingLabel())
        ->toBe('Fund account');
});
