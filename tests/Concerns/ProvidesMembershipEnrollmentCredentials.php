<?php

namespace Tests\Concerns;

use Livewire\Features\SupportTesting\Testable;

trait ProvidesMembershipEnrollmentCredentials
{
    protected function withEnrollmentPassword(Testable $test): Testable
    {
        return $test
            ->set('password', 'SecurePass1!')
            ->set('password_confirmation', 'SecurePass1!');
    }

    protected function withEnrollmentProfile(Testable $test, bool $withNextOfKin = true): Testable
    {
        $test = $test
            ->set('national_id', '1234567890')
            ->set('date_of_birth', '1990-01-15')
            ->set('address', '123 Main Street')
            ->set('city', 'Riyadh')
            ->set('mobile_phone', '+966501234567')
            ->set('bank_account_number', '1234567890123456')
            ->set('iban', 'SA0380000000608010167519');

        if ($withNextOfKin) {
            $test = $test
                ->set('next_of_kin_name', 'Mohammed Example')
                ->set('next_of_kin_phone', '+966509876543');
        }

        return $test;
    }

    protected function withEnrollmentFeePayment(Testable $test): Testable
    {
        return $test
            ->set('membership_fee_transfer_reference', 'TXN-REF-12345')
            ->set('membership_fee_acknowledged', true);
    }

    /**
     * Advance from step 1 through identity and work steps (after step 1 is filled).
     */
    protected function advanceThroughProfileSteps(Testable $test): Testable
    {
        return $this->withEnrollmentProfile($test)
            ->call('nextStep')
            ->call('nextStep');
    }
}
