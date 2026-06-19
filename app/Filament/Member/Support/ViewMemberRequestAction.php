<?php

declare(strict_types=1);

namespace App\Filament\Member\Support;

use App\Models\Tenant\MemberRequest;
use App\Support\MemberDateDisplay;
use Filament\Actions\ViewAction;

final class ViewMemberRequestAction
{
    public static function make(): ViewAction
    {
        return MemberPortalViewModal::apply(
            ViewAction::make()
                ->modalHeading(fn (MemberRequest $record): string => MemberRequest::typeLabel($record->type))
                ->modalContent(fn (MemberRequest $record) => MemberPortalViewModal::content(
                    self::sections($record),
                )),
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function sections(MemberRequest $record): array
    {
        $statusChip = match ($record->status) {
            MemberRequest::STATUS_PENDING => 'amber',
            MemberRequest::STATUS_APPROVED => 'green',
            MemberRequest::STATUS_REJECTED => 'red',
            default => 'gray',
        };

        $sections = [
            [
                'hero' => [
                    'label' => MemberRequest::typeLabel($record->type),
                    'subtitle' => MemberDateDisplay::format($record->created_at, 'M j, Y g:i A'),
                    'chip' => MemberRequest::statusOptions()[$record->status] ?? $record->status,
                    'chipVariant' => $statusChip,
                ],
            ],
            [
                'title' => __('Request details'),
                'columns' => 3,
                'items' => [
                    ['label' => __('Submitted'), 'value' => MemberDateDisplay::format($record->created_at, 'M j, Y g:i A') ?? __('—')],
                    ['label' => __('Status'), 'value' => MemberRequest::statusOptions()[$record->status] ?? $record->status],
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

        return $sections;
    }
}
