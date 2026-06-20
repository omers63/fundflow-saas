<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Support;

use App\Models\Tenant\NotificationLog;
use Filament\Actions\ViewAction;

final class ViewNotificationLogAction
{
    public static function make(): ViewAction
    {
        return TenantPortalViewModal::apply(
            ViewAction::make()
                ->modalHeading(fn (NotificationLog $record): string => $record->subject ?? __('Notification log'))
                ->modalContent(fn (NotificationLog $record) => TenantPortalViewModal::content(
                    self::sections($record),
                )),
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function sections(NotificationLog $record): array
    {
        $statusChip = match ($record->status) {
            'sent' => 'green',
            'failed' => 'red',
            'skipped' => 'gray',
            default => 'gray',
        };

        $sections = [
            [
                'hero' => [
                    'label' => self::channelLabel($record->channel),
                    'subtitle' => $record->user?->name ?? __('Unknown recipient'),
                    'chip' => ucfirst((string) $record->status),
                    'chipVariant' => $statusChip,
                    'chipSecondary' => $record->sent_at?->format('d M Y H:i'),
                    'chipSecondaryVariant' => 'gray',
                ],
            ],
            [
                'title' => __('Delivery'),
                'columns' => 3,
                'items' => [
                    ['label' => __('Recipient'), 'value' => $record->user?->name ?? __('—')],
                    ['label' => __('Email'), 'value' => $record->user?->email ?? __('—')],
                    ['label' => __('Channel'), 'value' => self::channelLabel($record->channel)],
                    ['label' => __('Status'), 'value' => ucfirst((string) $record->status)],
                    ['label' => __('Sent at'), 'value' => $record->sent_at?->format('d M Y H:i') ?? __('—')],
                    ['label' => __('Logged at'), 'value' => $record->created_at?->format('d M Y H:i') ?? __('—')],
                ],
            ],
            [
                'title' => __('Content'),
                'items' => [
                    ['label' => __('Subject'), 'value' => $record->subject ?? __('—')],
                ],
            ],
        ];

        if (filled($record->body)) {
            $sections[] = [
                'title' => __('Message body'),
                'html' => $record->body,
            ];
        }

        if (filled($record->error_message)) {
            $sections[] = [
                'title' => __('Error details'),
                'prose' => $record->error_message,
            ];
        }

        return $sections;
    }

    private static function channelLabel(?string $channel): string
    {
        return match ($channel) {
            'mail' => __('Email'),
            'database' => __('In-app'),
            'twilio' => __('SMS'),
            'whatsapp' => __('WhatsApp'),
            default => $channel ?? __('—'),
        };
    }
}
