<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Support;

use App\Models\Tenant\FundAuditLog;
use Filament\Actions\ViewAction;

final class ViewFundAuditLogAction
{
    public static function make(): ViewAction
    {
        return TenantPortalViewModal::apply(
            ViewAction::make()
                ->modalHeading(fn (FundAuditLog $record): string => $record->event_type)
                ->modalContent(fn (FundAuditLog $record) => TenantPortalViewModal::content(
                    self::sections($record),
                )),
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function sections(FundAuditLog $record): array
    {
        $payload = json_encode($record->payload ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '—';

        return [
            [
                'hero' => [
                    'label' => self::domainLabel($record->domain),
                    'subtitle' => $record->occurred_at?->format('d M Y H:i') ?? __('—'),
                    'chip' => $record->event_type,
                    'chipVariant' => 'sky',
                ],
            ],
            [
                'title' => __('Event details'),
                'columns' => 3,
                'items' => [
                    ['label' => __('Event type'), 'value' => $record->event_type],
                    ['label' => __('Domain'), 'value' => self::domainLabel($record->domain)],
                    ['label' => __('Occurred at'), 'value' => $record->occurred_at?->format('d M Y H:i') ?? __('—')],
                    ['label' => __('Member'), 'value' => $record->member?->name ?? __('—')],
                    ['label' => __('Operator'), 'value' => $record->operator?->name ?? __('—')],
                    ['label' => __('Checksum'), 'value' => $record->checksum ?? __('—')],
                ],
            ],
            [
                'title' => __('Payload'),
                'prose' => $payload,
            ],
        ];
    }

    private static function domainLabel(?string $domain): string
    {
        return match ($domain) {
            'reconciliation' => __('Reconciliation'),
            'migration' => __('Migration'),
            'ledger' => __('Ledger'),
            'contribution' => __('Contribution'),
            'loan' => __('Loan'),
            default => $domain ?? __('—'),
        };
    }
}
