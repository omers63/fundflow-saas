<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Tenant\Account;
use App\Models\Tenant\ReconciliationException;
use App\Models\Tenant\Setting;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

final class ReconciliationJournalComposerSchema
{
    public const MIN_LEGS = 2;

    public const MAX_LEGS = 16;

    /**
     * @return array<string, string>
     */
    public static function accountOptions(): array
    {
        return Account::query()
            ->with('member:id,name,member_number')
            ->orderByDesc('is_master')
            ->orderBy('type')
            ->orderBy('name')
            ->get()
            ->mapWithKeys(function (Account $account): array {
                $label = $account->is_master
                    ? __('Master :name (:type)', ['name' => $account->name, 'type' => $account->type])
                    : __(':name (:type)', [
                        'name' => $account->member
                            ? $account->member->name.' · '.$account->member->member_number
                            : $account->name,
                        'type' => $account->type,
                    ]);

                return [$account->id => $label];
            })
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaultFormState(?ReconciliationException $exception = null): array
    {
        $masterCash = Account::masterCash();
        $masterFund = Account::masterFund();
        $amount = $exception !== null && $exception->amount_delta !== null
            ? round(abs((float) $exception->amount_delta), 2)
            : 100.0;

        if ($amount <= 0) {
            $amount = 100.0;
        }

        $legs = [];

        if ($masterCash !== null && $masterFund !== null) {
            $legs = [
                [
                    'account_id' => $masterCash->id,
                    'type' => 'debit',
                    'amount' => $amount,
                ],
                [
                    'account_id' => $masterFund->id,
                    'type' => 'credit',
                    'amount' => $amount,
                ],
            ];
        } else {
            $first = Account::query()->orderBy('id')->first();
            $second = Account::query()->orderBy('id')->skip(1)->first();

            if ($first !== null && $second !== null) {
                $legs = [
                    ['account_id' => $first->id, 'type' => 'debit', 'amount' => $amount],
                    ['account_id' => $second->id, 'type' => 'credit', 'amount' => $amount],
                ];
            }
        }

        return [
            'legs' => $legs,
            'reason' => '',
        ];
    }

    /**
     * @return array<int, Placeholder|Repeater|Textarea>
     */
    public static function schema(?ReconciliationException $exception = null): array
    {
        $currency = Setting::get('general', 'currency', 'USD');

        return [
            Placeholder::make('composer_help')
                ->label(__('Balanced journal'))
                ->content(fn (): Htmlable => new HtmlString(
                    '<p class="text-sm text-gray-600 dark:text-gray-400">'
                    .e(__('Add debit and credit legs that sum to the same amount. All legs share one reconciliation reference.'))
                    .'</p>'
                )),
            Repeater::make('legs')
                ->label(__('Journal legs'))
                ->minItems(self::MIN_LEGS)
                ->maxItems(self::MAX_LEGS)
                ->defaultItems(self::MIN_LEGS)
                ->addActionLabel(__('Add leg'))
                ->reorderable()
                ->columns(3)
                ->schema([
                    Select::make('account_id')
                        ->label(__('Account'))
                        ->options(fn (): array => self::accountOptions())
                        ->searchable()
                        ->required(),
                    Select::make('type')
                        ->label(__('Side'))
                        ->options([
                            'debit' => __('Debit'),
                            'credit' => __('Credit'),
                        ])
                        ->required()
                        ->live(),
                    TextInput::make('amount')
                        ->label(__('Amount'))
                        ->numeric()
                        ->required()
                        ->minValue(0.01)
                        ->step(0.01)
                        ->live(onBlur: true),
                ]),
            Placeholder::make('balance_check')
                ->label(__('Debit / credit totals'))
                ->content(function (Get $get) use ($currency): Htmlable {
                    $debits = 0.0;
                    $credits = 0.0;

                    foreach ($get('legs') ?? [] as $leg) {
                        $amount = (float) ($leg['amount'] ?? 0);
                        $type = (string) ($leg['type'] ?? '');

                        if ($type === 'debit') {
                            $debits += $amount;
                        } elseif ($type === 'credit') {
                            $credits += $amount;
                        }
                    }

                    $delta = round($debits - $credits, 2);
                    $balanced = abs($delta) < 0.01;
                    $formattedDebits = MoneyDisplay::format($debits, $currency) ?? number_format($debits, 2);
                    $formattedCredits = MoneyDisplay::format($credits, $currency) ?? number_format($credits, 2);
                    $color = $balanced
                        ? 'text-success-600 dark:text-success-400'
                        : 'text-danger-600 dark:text-danger-400';

                    $status = $balanced
                        ? __('Balanced')
                        : __('Out of balance by :amount', ['amount' => MoneyDisplay::format(abs($delta), $currency) ?? number_format(abs($delta), 2)]);

                    return new HtmlString(
                        '<span class="text-sm font-medium '.$color.'">'
                        .e(__('Debits :debits · Credits :credits — :status', [
                            'debits' => $formattedDebits,
                            'credits' => $formattedCredits,
                            'status' => $status,
                        ]))
                        .'</span>'
                    );
                }),
            Textarea::make('reason')
                ->label(__('Reason'))
                ->required()
                ->rows(3)
                ->default($exception?->resolution_notes),
        ];
    }
}
