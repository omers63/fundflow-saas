<?php

namespace App\Filament\Support\ViewActions;

use App\Filament\Support\AccountDetailInsightsRefresh;
use App\Filament\Support\MoneyDisplay;
use App\Models\Tenant\Setting;
use App\Models\Tenant\Transaction;
use App\Services\AccountingService;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Exceptions\Halt;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;
use Throwable;

final class SplitAccountTransactionAction
{
    public static function make(): Action
    {
        return Action::make('splitTransaction')
            ->label(__('Split'))
            ->icon('heroicon-o-scissors')
            ->color('info')
            ->authorize(fn (): bool => (bool) Auth::guard('tenant')->user()?->is_admin)
            ->visible(fn (Transaction $record): bool => app(AccountingService::class)->canSplitTransaction($record))
            ->modalHeading(__('Split transaction'))
            ->modalDescription(fn (Transaction $record): string => self::modalDescription($record))
            ->modalSubmitActionLabel(__('Split into parts'))
            ->modalWidth('3xl')
            ->fillForm(fn (Transaction $record): array => self::defaultFormState($record))
            ->schema(fn (Transaction $record): array => self::formSchema($record))
            ->action(function (Transaction $record, array $data, AccountingService $accounting): void {
                $parts = collect($data['parts'] ?? [])
                    ->map(fn (array $part): array => [
                        'amount' => (float) ($part['amount'] ?? 0),
                        'description' => trim((string) ($part['description'] ?? '')),
                    ])
                    ->all();

                try {
                    $accounting->splitTransaction($record, $parts);
                } catch (Throwable $exception) {
                    Notification::make()
                        ->title(__('Split failed'))
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();

                    throw new Halt;
                }

                Notification::make()
                    ->title(__('Transaction split into :count parts', ['count' => count($parts)]))
                    ->success()
                    ->send();
            })
            ->after(fn (Transaction $record) => AccountDetailInsightsRefresh::dispatchLedgerChange((int) $record->account_id));
    }

    public static function modalDescription(Transaction $record): string
    {
        $currency = Setting::get('general', 'currency', 'USD');

        return __('Divide :amount into labelled parts. Parts must sum to the original amount.', [
            'amount' => MoneyDisplay::format((float) $record->amount, $currency) ?? (string) $record->amount,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaultFormState(Transaction $record): array
    {
        $half = round((float) $record->amount / 2, 2);
        $remainder = round((float) $record->amount - $half, 2);

        return [
            'parts' => [
                [
                    'category' => 'other',
                    'description' => $record->description ?? __('Part 1'),
                    'amount' => $half,
                ],
                [
                    'category' => 'other',
                    'description' => __('Part 2'),
                    'amount' => $remainder,
                ],
            ],
        ];
    }

    /**
     * @return array<int, Placeholder|Repeater>
     */
    public static function formSchema(Transaction $record): array
    {
        $currency = Setting::get('general', 'currency', 'USD');
        $originalAmount = (float) $record->amount;
        $signedDisplay = MoneyDisplay::format($record->getSignedAmount(), $currency);

        return [
            Placeholder::make('original_info')
                ->label(__('Original entry'))
                ->content(fn (): Htmlable => new HtmlString(
                    '<span class="text-base font-semibold">'
                    .e($signedDisplay)
                    .'</span>'
                    .' <span class="text-gray-500">('
                    .e(__($record->type === 'credit' ? 'Credit' : 'Debit'))
                    .')</span>'
                    .' — '
                    .e($record->description ?? '—')
                )),
            Repeater::make('parts')
                ->label(__('Split into parts'))
                ->minItems(2)
                ->defaultItems(2)
                ->addActionLabel(__('Add part'))
                ->columns(3)
                ->schema([
                    Select::make('category')
                        ->label(__('Category'))
                        ->options(self::categoryOptions())
                        ->required()
                        ->live()
                        ->afterStateUpdated(function (?string $state, callable $set): void {
                            $label = self::categoryOptions()[$state] ?? null;

                            if (filled($label) && $state !== 'other') {
                                $set('description', $label);
                            }
                        }),
                    TextInput::make('description')
                        ->label(__('Description'))
                        ->required()
                        ->maxLength(500),
                    TextInput::make('amount')
                        ->label(__('Amount'))
                        ->numeric()
                        ->required()
                        ->minValue(0.01)
                        ->step(0.01)
                        ->live(onBlur: true),
                ]),
            Placeholder::make('running_total')
                ->label(__('Running total'))
                ->content(function (Get $get) use ($originalAmount, $currency): Htmlable {
                    $total = collect($get('parts') ?? [])
                        ->sum(fn (array $part): float => (float) ($part['amount'] ?? 0));

                    $formattedTotal = MoneyDisplay::format($total, $currency) ?? number_format($total, 2);
                    $formattedOriginal = MoneyDisplay::format($originalAmount, $currency) ?? number_format($originalAmount, 2);
                    $matches = abs($total - $originalAmount) < 0.005;

                    $colorClass = $matches
                        ? 'text-success-600 dark:text-success-400'
                        : 'text-danger-600 dark:text-danger-400';

                    return new HtmlString(
                        '<span class="text-sm font-medium '.$colorClass.'">'
                        .e($formattedTotal)
                        .' / '
                        .e($formattedOriginal)
                        .'</span>'
                    );
                }),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function categoryOptions(): array
    {
        return [
            'contribution' => __('Contribution'),
            'loan' => __('Loan'),
            'repayment' => __('Repayment'),
            'fee' => __('Fee'),
            'refund' => __('Refund'),
            'adjustment' => __('Adjustment'),
            'other' => __('Other'),
        ];
    }
}
