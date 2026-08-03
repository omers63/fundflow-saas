<?php

declare(strict_types=1);

namespace App\Services;

use App\Filament\Tenant\Support\AuditSystemTabRegistry;
use App\Models\Tenant\InboundPayment;
use App\Models\Tenant\Setting;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Throwable;

final class InboundPaymentInstructionNotifier
{
    public function notifyCreated(InboundPayment $payment): void
    {
        try {
            Notification::make()
                ->title(__('Bank receipt expected'))
                ->body($this->instructionBody($payment))
                ->info()
                ->persistent()
                ->actions([
                    Action::make('openInboundRemittances')
                        ->label(__('Open inbound remittance list'))
                        ->url(AuditSystemTabRegistry::url('inbound_remittances'))
                        ->button(),
                ])
                ->send();
        } catch (Throwable) {
            // Console/tests without a session — remittance row is still stored.
        }
    }

    public function instructionBody(InboundPayment $payment): string
    {
        $currency = (string) Setting::get('general', 'currency', 'USD');
        $amount = number_format((float) $payment->amount, 2).' '.$currency;

        $parts = [
            __('Payer: :name', ['name' => $payment->payer_name]),
            __('Amount: :amount', ['amount' => $amount]),
            __('Reason: :reason', ['reason' => $payment->reason]),
            __('Date: :date', [
                'date' => $payment->instruction_date?->format('Y-m-d') ?? '—',
            ]),
            __('Type: :type', ['type' => $payment->typeLabel()]),
        ];

        if (filled($payment->payer_iban)) {
            $parts[] = __('IBAN: :iban', ['iban' => $payment->payer_iban]);
        }

        if (filled($payment->payer_bank_account_number)) {
            $parts[] = __('Account: :account', [
                'account' => $payment->payer_bank_account_number,
            ]);
        }

        $parts[] = __('Record the physical receipt or check in Audit & System → Inbound remittances when funds arrive.');

        return implode(' · ', $parts);
    }
}
