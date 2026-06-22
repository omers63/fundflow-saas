<?php

declare(strict_types=1);

namespace App\Support\SmsClearing;

use App\Filament\Tenant\Support\ViewSmsTransactionAction;
use App\Models\Tenant\SmsTransaction;
use App\Services\SmsClearingQueueService;
use Illuminate\Support\HtmlString;

final class SmsClearingQueuePresenter
{
    public static function sliceLabel(SmsTransaction $record): string
    {
        return match (app(SmsClearingQueueService::class)->sliceForRecord($record)) {
            'unmatched' => __('Unmatched member'),
            default => __('Ready to post'),
        };
    }

    public static function sliceColor(SmsTransaction $record): string
    {
        return app(SmsClearingQueueService::class)->isUnmatchedItem($record) ? 'amber' : 'emerald';
    }

    public static function kindLabel(SmsTransaction $record): string
    {
        return match ($record->transaction_type) {
            'credit' => __('Credit'),
            'debit' => __('Debit'),
            default => ucfirst((string) ($record->transaction_type ?? __('—'))),
        };
    }

    public static function kindColor(SmsTransaction $record): string
    {
        return $record->transaction_type === 'credit' ? 'success' : 'danger';
    }

    public static function suggestedActionLabel(SmsTransaction $record): ?string
    {
        return match (app(SmsClearingQueueService::class)->primaryActionForRecord($record)) {
            'postToCash' => app(SmsClearingQueueService::class)->isReadyToPostItem($record)
            ? __('Post to cash')
            : __('Assign member & post'),
            default => null,
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function modalSections(SmsTransaction $record): array
    {
        $sections = ViewSmsTransactionAction::sections($record);

        $suggested = self::suggestedActionLabel($record);

        $items = [
            ['label' => __('Queue slice'), 'value' => self::sliceLabel($record)],
            ['label' => __('Type'), 'value' => self::kindLabel($record)],
        ];

        if ($suggested !== null) {
            $items[] = ['label' => __('Suggested next step'), 'value' => $suggested];
        }

        $sections[] = [
            'title' => __('Work queue context'),
            'columns' => 1,
            'items' => $items,
        ];

        return $sections;
    }

    public static function contextHtml(SmsTransaction $record): HtmlString
    {
        $rows = collect([
            ['label' => __('Queue slice'), 'value' => self::sliceLabel($record)],
            ['label' => __('Type'), 'value' => self::kindLabel($record)],
            self::suggestedActionLabel($record) !== null
            ? ['label' => __('Suggested next step'), 'value' => (string) self::suggestedActionLabel($record)]
            : null,
        ])->filter();

        $html = $rows
            ->map(fn (array $row): string => '<div><span class="text-gray-500">'.e($row['label']).'</span> <span class="font-medium">'.e($row['value']).'</span></div>')
            ->implode('');

        return new HtmlString($html);
    }
}
