<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Support;

use App\Models\Tenant\PortalAccessLog;
use Filament\Actions\ViewAction;

final class ViewPortalAccessLogAction
{
    public static function make(): ViewAction
    {
        return TenantPortalViewModal::apply(
            ViewAction::make()
                ->modalHeading(fn (PortalAccessLog $record): string => $record->displayName())
                ->modalContent(fn (PortalAccessLog $record) => TenantPortalViewModal::content(
                    self::sections($record),
                )),
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function sections(PortalAccessLog $record): array
    {
        $panelLabel = match ($record->panel) {
            PortalAccessLog::PANEL_MEMBER => __('Member portal'),
            PortalAccessLog::PANEL_ADMIN => __('Admin portal'),
            default => $record->panel,
        };

        return [
            [
                'hero' => [
                    'label' => $record->displayName(),
                    'subtitle' => $panelLabel,
                    'chip' => $panelLabel,
                    'chipVariant' => $record->panel === PortalAccessLog::PANEL_MEMBER ? 'blue' : 'amber',
                    'chipSecondary' => $record->accessed_at?->format('d M Y H:i'),
                    'chipSecondaryVariant' => 'gray',
                ],
            ],
            [
                'title' => __('Access details'),
                'columns' => 3,
                'items' => [
                    ['label' => __('Member name'), 'value' => $record->displayName()],
                    ['label' => __('Member number'), 'value' => $record->member?->member_number ?? __('—')],
                    ['label' => __('Login email'), 'value' => $record->user?->email ?? __('—')],
                    ['label' => __('Portal'), 'value' => $panelLabel],
                    ['label' => __('IP address'), 'value' => $record->ip_address ?? __('—')],
                    ['label' => __('Accessed at'), 'value' => $record->accessed_at?->format('d M Y H:i') ?? __('—')],
                ],
            ],
            [
                'title' => __('Device'),
                'prose' => $record->user_agent ?: __('—'),
            ],
        ];
    }
}
