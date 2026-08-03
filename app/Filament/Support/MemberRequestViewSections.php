<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Tenant\MemberRequest;
use App\Support\MemberDateDisplay;

/**
 * Shared modal / detail section layout for member requests (tenant + member portal).
 */
final class MemberRequestViewSections
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function forAdmin(MemberRequest $record): array
    {
        $record->loadMissing(['requester', 'reviewedBy']);

        $sections = [
            self::hero($record, $record->requester?->name ?? __('—')),
            [
                'title' => __('Request details'),
                'columns' => 2,
                'items' => [
                    ['label' => __('Member'), 'value' => $record->requester?->name ?? __('—')],
                    ['label' => __('Member #'), 'value' => $record->requester?->member_number ?? __('—')],
                    ['label' => __('Type'), 'value' => MemberRequest::typeLabel($record->type)],
                    ['label' => __('Status'), 'value' => MemberRequest::statusOptions()[$record->status] ?? $record->status],
                    ['label' => __('Submitted'), 'value' => self::formatDateTime($record->created_at)],
                    ['label' => __('Reviewed by'), 'value' => $record->reviewedBy?->name ?? __('—')],
                    ['label' => __('Reviewed at'), 'value' => self::formatDateTime($record->reviewed_at)],
                ],
            ],
        ];

        return self::appendContentAndNote($sections, $record);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function forMember(MemberRequest $record): array
    {
        $sections = [
            self::hero($record, self::formatDateTime($record->created_at)),
            [
                'title' => __('Request details'),
                'columns' => 2,
                'items' => [
                    ['label' => __('Submitted'), 'value' => self::formatDateTime($record->created_at)],
                    ['label' => __('Status'), 'value' => MemberRequest::statusOptions()[$record->status] ?? $record->status],
                    ['label' => __('Type'), 'value' => MemberRequest::typeLabel($record->type)],
                ],
            ],
        ];

        return self::appendContentAndNote($sections, $record);
    }

    /**
     * @return array<string, mixed>
     */
    private static function hero(MemberRequest $record, string $subtitle): array
    {
        $chipVariant = match ($record->status) {
            MemberRequest::STATUS_PENDING => 'amber',
            MemberRequest::STATUS_APPROVED => 'green',
            MemberRequest::STATUS_REJECTED => 'red',
            default => 'gray',
        };

        return [
            'hero' => [
                'label' => MemberRequest::typeLabel($record->type),
                'subtitle' => $subtitle,
                'chip' => MemberRequest::statusOptions()[$record->status] ?? $record->status,
                'chipVariant' => $chipVariant,
            ],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $sections
     * @return array<int, array<string, mixed>>
     */
    private static function appendContentAndNote(array $sections, MemberRequest $record): array
    {
        $detailItems = $record->payloadDetailItems();

        if ($detailItems !== []) {
            $sections[] = [
                'title' => __('What was requested'),
                'columns' => 2,
                'items' => $detailItems,
            ];
        }

        if (filled($record->admin_note)) {
            $sections[] = [
                'title' => __('Admin note'),
                'prose' => $record->admin_note,
            ];
        }

        return $sections;
    }

    private static function formatDateTime(mixed $value): string
    {
        if ($value === null || $value === '') {
            return __('—');
        }

        if (class_exists(MemberDateDisplay::class)) {
            return MemberDateDisplay::format($value, 'd M Y H:i') ?? __('—');
        }

        return (string) $value;
    }
}
