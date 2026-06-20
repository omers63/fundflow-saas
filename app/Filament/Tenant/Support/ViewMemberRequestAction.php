<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Support;

use App\Models\Tenant\MemberRequest;
use Filament\Actions\Action;

final class ViewMemberRequestAction
{
    public static function make(): Action
    {
        return TenantPortalViewModal::apply(
            Action::make('viewPayload')
                ->label(__('Details'))
                ->icon('heroicon-o-eye')
                ->color('gray')
                ->modalHeading(__('Request payload'))
                ->modalContent(fn (MemberRequest $record) => TenantPortalViewModal::content(
                    self::sections($record),
                )),
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function sections(MemberRequest $record): array
    {
        $chipVariant = match ($record->status) {
            MemberRequest::STATUS_PENDING => 'amber',
            MemberRequest::STATUS_APPROVED => 'green',
            MemberRequest::STATUS_REJECTED => 'red',
            default => 'gray',
        };

        $sections = [
            [
                'hero' => [
                    'label' => MemberRequest::typeLabel($record->type),
                    'subtitle' => $record->requester?->name ?? __('—'),
                    'chip' => MemberRequest::statusOptions()[$record->status] ?? $record->status,
                    'chipVariant' => $chipVariant,
                ],
            ],
            [
                'title' => __('Request details'),
                'columns' => 2,
                'items' => [
                    ['label' => __('Member'), 'value' => $record->requester?->name ?? __('—')],
                    ['label' => __('Member #'), 'value' => $record->requester?->member_number ?? __('—')],
                    ['label' => __('Type'), 'value' => MemberRequest::typeLabel($record->type)],
                    ['label' => __('Status'), 'value' => MemberRequest::statusOptions()[$record->status] ?? $record->status],
                    ['label' => __('Submitted'), 'value' => $record->created_at?->locale(app()->getLocale())->translatedFormat('d M Y H:i') ?? __('—')],
                    ['label' => __('Reviewed by'), 'value' => $record->reviewedBy?->name ?? __('—')],
                    ['label' => __('Reviewed at'), 'value' => $record->reviewed_at?->locale(app()->getLocale())->translatedFormat('d M Y H:i') ?? __('—')],
                ],
            ],
            [
                'title' => __('Summary'),
                'prose' => $record->describePayload(),
            ],
        ];

        if (filled($record->admin_note)) {
            $sections[] = [
                'title' => __('Admin note'),
                'prose' => $record->admin_note,
            ];
        }

        $sections[] = [
            'title' => __('Payload'),
            'prose' => $record->payloadAsPlainText(),
        ];

        return $sections;
    }
}
