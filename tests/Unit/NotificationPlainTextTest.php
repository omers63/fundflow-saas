<?php

use App\Support\NotificationPlainText;
use App\Support\Notifications\FundPostingNotificationFormatter;

test('notification plain text strips html markup', function () {
    $html = '<p class="ff-notification-lead">Deposit accepted</p>'
        . '<dl class="ff-notification-details"><dt>Amount</dt><dd><strong>500.00</strong></dd></dl>';

    expect(NotificationPlainText::from($html))
        ->toBe("Deposit accepted\nAmount\n500.00");
});

test('fund posting notification details use valid description list markup', function () {
    $html = FundPostingNotificationFormatter::renderDetails([
        [
            'label' => 'Amount',
            'value' => '500.00',
            'emphasis' => true,
        ],
    ]);

    expect($html)
        ->toContain('<dl class="ff-notification-details">')
        ->toContain('<dt class="ff-notification-details__label">Amount</dt>')
        ->toContain('<dd class="ff-notification-details__value">')
        ->not->toContain('<div class="ff-notification-details__row">');
});
