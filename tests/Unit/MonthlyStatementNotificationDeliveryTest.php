<?php

declare(strict_types=1);

use App\Models\Tenant\MonthlyStatement;
use App\Models\Tenant\Setting;
use App\Models\Tenant\User;
use App\Notifications\Tenant\MonthlyStatementNotification;
use App\Support\CommunicationSettings;
use App\Support\StatementSettings;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
});

test('statement notification delivery modes select mail or non-mail channels', function () {
    Setting::set(StatementSettings::GROUP, 'auto_email', true);
    Setting::set(CommunicationSettings::GROUP, 'in_app_enabled', true);
    Setting::set(CommunicationSettings::GROUP, 'email_enabled', true);

    $user = User::create([
        'name' => 'Statement Delivery',
        'email' => 'statement-delivery@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
    ]);

    $statement = new MonthlyStatement(['period' => '2026-05']);

    $default = new MonthlyStatementNotification($statement, MonthlyStatementNotification::DELIVERY_DEFAULT);
    $notify = new MonthlyStatementNotification($statement, MonthlyStatementNotification::DELIVERY_NOTIFY);
    $email = new MonthlyStatementNotification($statement, MonthlyStatementNotification::DELIVERY_EMAIL);

    expect($default->via($user))->toContain('mail')
        ->and($default->via($user))->toContain('database')
        ->and($notify->via($user))->toContain('database')
        ->and($notify->via($user))->not->toContain('mail')
        ->and($email->via($user))->toBe(['mail']);
});
