<?php

namespace App\Filament\Support\ViewActions;

use App\Filament\Support\AccountDetailInsightsRefresh;
use App\Filament\Support\ActionModalFailure;
use App\Filament\Support\MoneyDisplay;
use App\Models\Tenant\Setting;
use App\Models\Tenant\Transaction;
use App\Services\AccountingService;
use App\Support\BusinessDay;
use App\Support\LedgerSettings;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;
use Throwable;

final class ReverseAccountTransactionAction
{
    public static function make(): Action
    {
        return Action::make('reverseEntry')
            ->label(__('Reverse'))
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('danger')
            ->authorize(fn (): bool => (bool) Auth::guard('tenant')->user()?->is_admin)
            ->visible(fn (Transaction $record): bool => LedgerSettings::showSplitReverse() && self::canReverse($record))
            ->modalHeading(__('Reverse'))
            ->modalDescription(fn (Transaction $record): Htmlable => self::modalDescription($record))
            ->modalSubmitActionLabel(__('Reverse'))
            ->modalWidth('md')
            ->schema(fn (Transaction $record): array => self::formSchema($record))
            ->action(function (Transaction $record, array $data, Action $action, AccountingService $accounting): void {
                $reason = (string) $data['reason'];
                $transactedAt = Carbon::parse($data['transacted_at']);

                try {
                    if ((bool) ($data['reverse_all_related'] ?? false)) {
                        if (! $accounting->canUseFullSourceReversal($record)) {
                            ActionModalFailure::present(
                                $action,
                                __('This transaction has no shared source — use single-entry reversal instead.'),
                                __('Reversal failed'),
                            );
                        }

                        $count = $accounting->createFullSourceReversal($record, $reason, $transactedAt);

                        Notification::make()
                            ->title(__('Reversal posted'))
                            ->body(__('Created :count reversal entries for related ledger lines.', ['count' => $count]))
                            ->success()
                            ->send();

                        return;
                    }

                    $accounting->createReversalEntry($record, $reason, $transactedAt);

                    Notification::make()
                        ->title(__('Reversal posted'))
                        ->success()
                        ->send();
                } catch (Throwable $exception) {
                    ActionModalFailure::present($action, $exception->getMessage(), __('Reversal failed'));
                }
            })
            ->after(fn (Transaction $record) => AccountDetailInsightsRefresh::dispatchLedgerChange((int) $record->account_id));
    }

    public static function canReverse(Transaction $record): bool
    {
        if (! (bool) Auth::guard('tenant')->user()?->is_admin) {
            return false;
        }

        $record->loadMissing('account');

        $account = $record->account;

        return $account !== null;
    }

    private static function modalDescription(Transaction $record): Htmlable
    {
        $currency = Setting::get('general', 'currency', 'USD');
        $signedAmount = MoneyDisplay::format($record->getSignedAmount(), $currency);
        $typeLabel = $record->type === 'credit' ? __('Credit') : __('Debit');

        return new HtmlString(
            '<p class="text-sm text-gray-600 dark:text-gray-400">'
            .e(__('Posts an equal-and-opposite entry on this account. The original line stays in the audit trail.'))
            .'</p>'
            .'<div class="mt-2 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-xs text-gray-600 dark:border-white/10 dark:bg-white/5 dark:text-gray-300">'
            .'<div><span class="font-medium">'.e(__('Transaction #:id', ['id' => $record->id])).'</span>'
            .' · '.e($typeLabel)
            .' · '.e($signedAmount ?? '—')
            .'</div>'
            .'<div class="truncate text-gray-400 dark:text-gray-500">'.e($record->description ?? '—').'</div>'
            .'</div>'
        );
    }

    /**
     * @return array<int, Placeholder|Textarea|DateTimePicker|Toggle>
     */
    private static function formSchema(Transaction $record): array
    {
        $accounting = app(AccountingService::class);

        return [
            Placeholder::make('already_reversal_warning')
                ->label('')
                ->content(new HtmlString(
                    '<div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-700 dark:border-amber-800/50 dark:bg-amber-950/30 dark:text-amber-300">'
                    .e(__('This entry is itself a reversal. Reversing it again will create another corrective entry.'))
                    .'</div>'
                ))
                ->visible(fn (): bool => $accounting->isReversalEntry($record)),
            Placeholder::make('existing_reversal_warning')
                ->label('')
                ->content(new HtmlString(
                    '<div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-700 dark:border-amber-800/50 dark:bg-amber-950/30 dark:text-amber-300">'
                    .e(__('A reversal entry already exists for this row. You may still proceed to create another reversal.'))
                    .'</div>'
                ))
                ->visible(fn (): bool => $accounting->hasExistingReversal($record)),
            Textarea::make('reason')
                ->label(__('Reason for reversal'))
                ->required()
                ->rows(2)
                ->maxLength(500)
                ->helperText(__('Appears in the counter-entry description for audit purposes.')),
            DateTimePicker::make('transacted_at')
                ->label(__('Transaction date & time'))
                ->default(BusinessDay::now())
                ->required()
                ->native(false)
                ->seconds(true),
            Toggle::make('reverse_all_related')
                ->label(__('Reverse all related entries (same source)'))
                ->helperText(function () use ($record, $accounting): string {
                    if ($accounting->isReversalEntry($record)) {
                        return __('This line is a reversal of transaction #:id — only this line will be reversed.', [
                            'id' => $record->reference_id,
                        ]);
                    }

                    if (blank($record->reference_type) || blank($record->reference_id)) {
                        return __('No linked source (e.g. manual credit/debit) — only this line will be reversed.');
                    }

                    if (! $accounting->canUseFullSourceReversal($record)) {
                        return __('No shared source — only this line will be reversed.');
                    }

                    $source = class_basename((string) $record->reference_type).' #'.$record->reference_id;
                    $total = $accounting->countRelatedLedgerEntries($record);

                    if ($total <= 1) {
                        return __('Only one ledger line exists for :source — toggling this has the same effect as a single reversal.', [
                            'source' => $source,
                        ]);
                    }

                    return __('Also reverses :count other ledger line(s) tied to the same :source across all accounts.', [
                        'count' => $total - 1,
                        'source' => $source,
                    ]);
                })
                ->default(false)
                ->live(),
        ];
    }
}
