<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Tenant\BankStatement;
use App\Services\ManualBankStatementLineService;
use App\Support\BankStatementBuckets;
use App\Support\BusinessDay;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * Header actions to add manual lines on real bank statements or operational clearance buckets.
 */
final class BankTransactionManualHeaderActions
{
    /**
     * @param  Closure(): BankStatement  $resolveStatement
     * @param  (Closure(): mixed)|null  $after
     * @return array<int, Action>
     */
    public static function make(Closure $resolveStatement, ?Closure $after = null): array
    {
        return [
            self::addLine($resolveStatement, $after),
        ];
    }

    /**
     * @param  Closure(): BankStatement  $resolveStatement
     * @param  (Closure(): mixed)|null  $after
     */
    public static function addLine(Closure $resolveStatement, ?Closure $after = null): Action
    {
        return LedgerToolbarAction::apply(
            Action::make('addManualBankLine')
                ->label(__('Add line'))
                ->icon(Heroicon::OutlinedPlus)
                ->color('primary')
                ->visible(function () use ($resolveStatement): bool {
                    return ManualBankStatementLineService::supportsManualLines($resolveStatement());
                })
                ->modalHeading(function () use ($resolveStatement): string {
                    return ManualBankStatementLineService::isOperationalClearance($resolveStatement())
                        ? __('Add operational line')
                        : __('Add bank statement line');
                })
                ->modalDescription(function () use ($resolveStatement): string {
                    $statement = $resolveStatement();

                    if (ManualBankStatementLineService::isOperationalClearance($statement)) {
                        return __('Creates the linked operation and an uncleared clearance row on this statement for bank matching.');
                    }

                    return __('Creates an imported line on this statement for clearing or posting. It does not post the master bank ledger by itself.');
                })
                ->modalSubmitActionLabel(__('Add line'))
                ->modalWidth('md')
                ->schema(fn (): array => self::formSchema($resolveStatement()))
                ->action(function (array $data, Action $action, ManualBankStatementLineService $service) use ($resolveStatement, $after): void {
                    $statement = $resolveStatement();
                    $fixedKind = ManualBankStatementLineService::fixedKind($statement);
                    $kind = $fixedKind ?? (string) $data['kind'];

                    if (
                        ! ActionModalFailure::attemptThrowable(
                            $action,
                            fn () => $service->create(
                                $statement,
                                $kind,
                                (float) $data['amount'],
                                (string) $data['description'],
                                (string) $data['transaction_date'],
                                filled($data['reference'] ?? null) ? (string) $data['reference'] : null,
                                filled($data['transaction_type'] ?? null) ? (string) $data['transaction_type'] : null,
                                filled($data['member_id'] ?? null) ? (int) $data['member_id'] : null,
                            ),
                            __('Could not add bank line'),
                        )
                    ) {
                        return;
                    }

                    Notification::make()
                        ->title(__('Bank line added'))
                        ->success()
                        ->send();

                    if ($after !== null) {
                        $after();
                    }
                }),
        );
    }

    /**
     * @return array<int, mixed>
     */
    private static function formSchema(BankStatement $statement): array
    {
        $operational = ManualBankStatementLineService::isOperationalClearance($statement);
        $fixedKind = ManualBankStatementLineService::fixedKind($statement);
        $requiresMember = ManualBankStatementLineService::requiresMember($statement);

        $fields = [];

        if ($fixedKind !== null) {
            $fields[] = Hidden::make('kind')->default($fixedKind);
        } else {
            $fields[] = Select::make('kind')
                ->label(__('Kind'))
                ->options(ManualBankStatementLineService::kindOptions())
                ->required()
                ->native(false)
                ->default(ManualBankStatementLineService::KIND_CREDIT);
        }

        if (! $operational) {
            $fields[] = Select::make('transaction_type')
                ->label(__('Type'))
                ->options(ManualBankStatementLineService::typeOptions())
                ->native(false)
                ->placeholder(__('Optional'));
        }

        $fields[] = TextInput::make('amount')
            ->label(__('Amount'))
            ->numeric()
            ->minValue(0.01)
            ->required()
            ->helperText($operational
                ? __('Enter a positive amount. Sign is fixed for this statement type.')
                : __('Enter a positive amount. Kind sets credit vs debit.'));

        $fields[] = DatePicker::make('transaction_date')
            ->label(__('Date'))
            ->native(false)
            ->required()
            ->default(fn (): string => BusinessDay::today()->toDateString());

        $fields[] = Textarea::make('description')
            ->label(__('Description'))
            ->rows(2)
            ->required()
            ->maxLength(500);

        if (! $operational || $statement->filename === BankStatementBuckets::MEMBER_POSTINGS) {
            $fields[] = TextInput::make('reference')
                ->label(__('Reference'))
                ->maxLength(255);
        }

        if ($requiresMember || ! $operational) {
            $fields[] = MemberSelect::configure(
                Select::make('member_id')
                    ->label(__('Member'))
                    ->required($requiresMember)
                    ->placeholder($requiresMember ? null : __('Unassigned')),
            );
        }

        return $fields;
    }
}
