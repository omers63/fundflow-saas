<?php

declare(strict_types=1);

namespace App\Support\BankClearing;

use App\Filament\Tenant\Support\ViewBankTransactionAction;
use App\Models\Tenant\BankTransaction;
use App\Services\BankClearingMatchService;
use App\Services\BankClearingQueueService;
use Illuminate\Support\HtmlString;

final class BankClearingQueuePresenter
{
    public static function sliceLabel(BankTransaction $record): string
    {
        $slice = app(BankClearingQueueService::class)->sliceForRecord($record);

        return match ($slice) {
            'operations' => __('From operations'),
            default => __('From bank file'),
        };
    }

    public static function sliceColor(BankTransaction $record): string
    {
        return app(BankClearingQueueService::class)->sliceForRecord($record) === 'operations'
            ? 'warning'
            : 'info';
    }

    public static function kindLabel(BankTransaction $record): string
    {
        $queue = app(BankClearingQueueService::class);

        return BankClearingQueueKind::forRecord($record, $queue->isBankFileItem($record))->label();
    }

    public static function kindColor(BankTransaction $record): string
    {
        if (app(BankClearingQueueService::class)->isBankFileItem($record)) {
            return 'sky';
        }

        return match (BankClearingQueueKind::forRecord($record, false)) {
            BankClearingQueueKind::ReturnIn => 'success',
            BankClearingQueueKind::InvestOut => 'warning',
            BankClearingQueueKind::Fee => 'info',
            BankClearingQueueKind::Expense => 'danger',
            BankClearingQueueKind::CashOut => 'warning',
            BankClearingQueueKind::Deposit => 'success',
            BankClearingQueueKind::BankImport => 'sky',
        };
    }

    public static function suggestedActionLabel(BankTransaction $record): ?string
    {
        $matching = app(BankClearingMatchService::class);

        if ($matching->findUniqueCandidate($record) !== null) {
            return __('Match automatically');
        }

        return match (app(BankClearingQueueService::class)->primaryActionForRecord($record)) {
            'matchToBankLine' => __('Match to bank line'),
            'mirrorToCash' => __('Post to cash'),
            'postToMember' => __('Post to member'),
            'clearWithoutEvidence' => __('Clear without evidence'),
            default => null,
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function modalSections(BankTransaction $record): array
    {
        $sections = ViewBankTransactionAction::sections($record);

        $suggested = self::suggestedActionLabel($record);

        $items = [
            ['label' => __('Queue slice'), 'value' => self::sliceLabel($record)],
            ['label' => __('Kind'), 'value' => self::kindLabel($record)],
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

    public static function contextHtml(BankTransaction $record): HtmlString
    {
        $rows = collect([
            ['label' => __('Queue slice'), 'value' => self::sliceLabel($record)],
            ['label' => __('Kind'), 'value' => self::kindLabel($record)],
            self::suggestedActionLabel($record) !== null
            ? ['label' => __('Suggested next step'), 'value' => (string) self::suggestedActionLabel($record)]
            : null,
        ])->filter()->map(function (array $item): string {
            return '<div class="flex flex-col gap-0.5 sm:flex-row sm:items-center sm:justify-between">'
                .'<dt class="text-xs font-medium text-gray-500 dark:text-gray-400">'.e($item['label']).'</dt>'
                .'<dd class="text-sm font-medium text-gray-900 dark:text-white">'.e($item['value']).'</dd>'
                .'</div>';
        })->implode('');

        return new HtmlString('<dl class="ff-bank-clearing-context space-y-3">'.$rows.'</dl>');
    }
}
