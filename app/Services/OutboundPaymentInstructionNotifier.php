<?php

declare(strict_types=1);

namespace App\Services;

use App\Filament\Tenant\Support\AuditSystemTabRegistry;
use App\Models\Tenant\OutboundPayment;
use App\Models\Tenant\Setting;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Throwable;

final class OutboundPaymentInstructionNotifier
{
    public function notifyCreated(OutboundPayment $payment): void
    {
        try {
            Notification::make()
                ->title(__('Bank payout required'))
                ->body($this->instructionBody($payment))
                ->warning()
                ->persistent()
                ->actions([
                    Action::make('openRemittances')
                        ->label(__('Open outbound remittance list'))
                        ->url(AuditSystemTabRegistry::url('remittances'))
                        ->button(),
                ])
                ->send();
        } catch (Throwable) {
            // Console/tests without a session — remittance row is still stored.
        }
    }

    public function instructionBody(OutboundPayment $payment): string
    {
        $currency = (string) Setting::get('general', 'currency', 'USD');
        $amount = number_format((float) $payment->amount, 2).' '.$currency;

        $parts = [
            __('Payee: :name', ['name' => $payment->payee_name]),
            __('Amount: :amount', ['amount' => $amount]),
            __('Reason: :reason', ['reason' => $payment->reason]),
            __('Date: :date', [
                'date' => $payment->instruction_date?->format('Y-m-d') ?? '—',
            ]),
            __('Type: :type', ['type' => $payment->typeLabel()]),
        ];

        if (filled($payment->payee_iban)) {
            $parts[] = __('IBAN: :iban', ['iban' => $payment->payee_iban]);
        }

        if (filled($payment->payee_bank_account_number)) {
            $parts[] = __('Account: :account', [
                'account' => $payment->payee_bank_account_number,
            ]);
        }

        $parts[] = __('Record the physical transfer or check in Audit & System → Outbound remittances when sent.');

        return implode(' · ', $parts);
    }
}
