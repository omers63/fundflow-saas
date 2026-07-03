<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Support;

use App\Filament\Support\MoneyDisplay;
use App\Filament\Support\ViewActions\ViewAccountTransactionAction as SharedViewAccountTransactionAction;
use App\Models\Tenant\Setting;
use App\Models\Tenant\Transaction;
use Filament\Actions\ViewAction;

final class ViewAccountTransactionAction
{
    public static function make(): ViewAction
    {
        return TenantPortalViewModal::apply(
            ViewAction::make()
                ->modalHeading(fn (Transaction $record): string => filled($record->description)
                    ? $record->displayDescription()
                    : __('Transaction #:id', ['id' => $record->id]))
                ->modalContent(fn (Transaction $record) => TenantPortalViewModal::content(
                    self::sections($record),
                )),
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function sections(Transaction $record): array
    {
        $record->loadMissing(['account', 'member', 'reference']);

        $currency = Setting::get('general', 'currency', 'USD');
        $signedAmount = MoneyDisplay::format($record->getSignedAmount(), $currency) ?? __('—');
        $balanceAfter = MoneyDisplay::format((float) $record->balance_after, $currency) ?? __('—');
        $heading = filled($record->description)
            ? $record->displayDescription()
            : __('Transaction #:id', ['id' => $record->id]);

        $sections = [
            [
                'hero' => [
                    'label' => $heading,
                    'amount' => $signedAmount,
                    'type' => $record->type,
                    'chip' => Transaction::typeLabel($record->type),
                    'chipVariant' => $record->type === 'credit' ? 'green' : 'gray',
                    'chipSecondary' => $record->transacted_at?->format('d M Y H:i'),
                    'chipSecondaryVariant' => 'gray',
                    'subtitle' => $record->ledgerAccountLabel(),
                ],
            ],
            [
                'title' => __('Details'),
                'columns' => 3,
                'items' => array_values(array_filter([
                    ['label' => __('Account'), 'value' => $record->ledgerAccountLabel()],
                    ['label' => __('Balance after'), 'value' => $balanceAfter],
                    ['label' => __('Linked source'), 'value' => $record->linkedSourceDetail()],
                    ['label' => __('Created'), 'value' => $record->created_at?->format('d M Y H:i') ?? __('—')],
                    filled($record->member?->name)
                    ? ['label' => __('Member tag'), 'value' => $record->member->name]
                    : null,
                ])),
            ],
        ];

        $referenceSection = SharedViewAccountTransactionAction::referenceModalSection(
            $record,
            memberFacing: false,
            heading: $heading,
        );

        if ($referenceSection !== null) {
            $sections[] = $referenceSection;
        }

        return $sections;
    }
}
