<?php

use App\Models\Tenant\Loan;
use App\Models\Tenant\User;
use App\Notifications\Tenant\GuarantorLoanApplicationNotification;
use App\Notifications\Tenant\LoanSubmittedNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Tests\TestCase;

uses(TestCase::class);

test('member channel notifications build mail messages from array payload', function () {
    $loan = new Loan([
        'amount_requested' => 15000,
    ]);

    $user = new User(['email' => 'borrower@test.com']);

    $submittedMail = (new LoanSubmittedNotification($loan))->toMail($user);
    expect($submittedMail)->toBeInstanceOf(MailMessage::class)
        ->and($submittedMail->subject)->toBe(__('Loan application submitted'));

    $guarantorMail = (new GuarantorLoanApplicationNotification($loan))->toMail($user);
    expect($guarantorMail)->toBeInstanceOf(MailMessage::class)
        ->and($guarantorMail->subject)->toBe(__('Guarantor request'));
});
