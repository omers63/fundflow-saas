<?php

declare(strict_types=1);

namespace App\Filament\Member\Support;

use App\Models\Tenant\SupportRequest;
use App\Support\MemberDateDisplay;
use Filament\Actions\ViewAction;

final class ViewSupportRequestAction
{
    public static function make(): ViewAction
    {
        return MemberPortalViewModal::apply(
            ViewAction::make()
                ->modalHeading(fn (SupportRequest $record): string => $record->subject)
                ->modalContent(fn (SupportRequest $record) => MemberPortalViewModal::content(
                    self::sections($record),
                )),
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function sections(SupportRequest $record): array
    {
        $record->loadMissing('replies.user');

        $statusChip = match ($record->status) {
            SupportRequest::STATUS_OPEN => 'amber',
            SupportRequest::STATUS_IN_PROGRESS => 'sky',
            SupportRequest::STATUS_RESOLVED => 'green',
            default => 'gray',
        };

        $sections = [
            [
                'hero' => [
                    'label' => $record->subject,
                    'subtitle' => SupportRequest::categoryLabel($record->category),
                    'chip' => SupportRequest::statusOptions()[$record->status] ?? $record->status,
                    'chipVariant' => $statusChip,
                ],
            ],
            [
                'title' => __('Request details'),
                'columns' => 2,
                'items' => [
                    ['label' => __('Submitted'), 'value' => MemberDateDisplay::format($record->created_at, 'M j, Y g:i A') ?? __('—')],
                    ['label' => __('Category'), 'value' => SupportRequest::categoryLabel($record->category)],
                ],
            ],
            [
                'title' => __('Your message'),
                'prose' => $record->message,
            ],
        ];

        if ($record->replies->isNotEmpty()) {
            $sections[] = [
                'title' => __('Admin responses'),
                'prose' => $record->replies
                    ->map(fn ($reply): string => ($reply->user?->name ?? __('Administrator'))
                        .' · '.(MemberDateDisplay::format($reply->created_at, 'M j, Y g:i A') ?? '')
                        ."\n".$reply->body)
                    ->implode("\n\n—\n\n"),
            ];
        }

        return $sections;
    }
}
