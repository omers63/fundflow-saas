<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Support;

use App\Models\Tenant\SupportRequest;
use Filament\Actions\Action;

final class ViewSupportRequestAction
{
    public static function make(): Action
    {
        return TenantPortalViewModal::apply(
            Action::make('viewMessage')
                ->label(__('View'))
                ->icon('heroicon-o-eye')
                ->color('gray')
                ->modalHeading(fn (SupportRequest $record): string => __('Support request #:id', ['id' => $record->id]))
                ->modalContent(fn (SupportRequest $record) => TenantPortalViewModal::content(
                    self::sections($record),
                )),
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function sections(SupportRequest $record): array
    {
        $name = $record->user?->name ?? __('—');
        $memberLine = $record->member
            ? $name.' ('.$record->member->member_number.')'
            : $name;

        return [
            [
                'hero' => [
                    'label' => $record->subject,
                    'subtitle' => SupportRequest::categoryLabel($record->category),
                    'chip' => __('Support request'),
                    'chipVariant' => 'sky',
                ],
            ],
            [
                'title' => __('Request details'),
                'columns' => 2,
                'items' => [
                    ['label' => __('From'), 'value' => $memberLine],
                    ['label' => __('Category'), 'value' => SupportRequest::categoryLabel($record->category)],
                    ['label' => __('Submitted'), 'value' => $record->created_at?->locale(app()->getLocale())->translatedFormat('d M Y H:i') ?? __('—')],
                ],
            ],
            [
                'title' => __('Message'),
                'prose' => $record->message,
            ],
        ];
    }
}
