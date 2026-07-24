<?php

declare(strict_types=1);

use App\Listeners\LogWebPushDeliveryListener;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\MessageSentReport;
use Mockery\MockInterface;
use NotificationChannels\WebPush\Events\NotificationFailed;
use NotificationChannels\WebPush\Events\NotificationSent;
use NotificationChannels\WebPush\PushSubscription;
use NotificationChannels\WebPush\WebPushMessageInterface;

test('web push sent listener swallows logging failures', function () {
    Log::shouldReceive('info')
        ->once()
        ->andThrow(new UnexpectedValueException('Permission denied'));

    $report = Mockery::mock(MessageSentReport::class);
    $report->shouldReceive('getEndpoint')->andReturn('https://example.test/push');

    $subscription = Mockery::mock(PushSubscription::class, function (MockInterface $mock): void {
        $mock->shouldReceive('getKey')->andReturn(1);
    });

    $message = Mockery::mock(WebPushMessageInterface::class);

    $event = new NotificationSent($report, $subscription, $message);

    expect(fn () => (new LogWebPushDeliveryListener)->handleSent($event))
        ->not->toThrow(Throwable::class);
});

test('web push failed listener swallows logging failures', function () {
    Log::shouldReceive('warning')
        ->once()
        ->andThrow(new UnexpectedValueException('Permission denied'));

    $report = Mockery::mock(MessageSentReport::class);
    $report->shouldReceive('getEndpoint')->andReturn('https://example.test/push');
    $report->shouldReceive('getReason')->andReturn('gone');
    $report->shouldReceive('isSubscriptionExpired')->andReturn(true);

    $subscription = Mockery::mock(PushSubscription::class, function (MockInterface $mock): void {
        $mock->shouldReceive('getKey')->andReturn(2);
    });

    $message = Mockery::mock(WebPushMessageInterface::class);

    $event = new NotificationFailed($report, $subscription, $message);

    expect(fn () => (new LogWebPushDeliveryListener)->handleFailed($event))
        ->not->toThrow(Throwable::class);
});
