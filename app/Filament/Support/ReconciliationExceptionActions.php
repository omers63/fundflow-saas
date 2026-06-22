<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\ReconciliationException;
use App\Models\Tenant\User;
use App\Services\ReconciliationResolutionService;
use App\Support\Lang;
use App\Support\Reconciliation\ReconciliationExceptionPresenter;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Auth;

final class ReconciliationExceptionActions
{
    /**
     * @return array<int, Action|ActionGroup>
     */
    public static function recordActions(): array
    {
        return [
            self::viewAction(),
            self::actionsMenu(),
        ];
    }

    public static function actionsMenu(): ActionGroup
    {
        return ActionGroup::make([
            ActionGroup::make(self::resolveActions())
                ->label(__('Resolve'))
                ->icon('heroicon-o-check-circle'),
            ActionGroup::make(self::ledgerActions())
                ->label(__('Ledger'))
                ->icon('heroicon-o-calculator'),
            ActionGroup::make(self::workflowActions())
                ->label(__('Workflow'))
                ->icon('heroicon-o-user-circle'),
            self::deleteAction(),
        ])
            ->label(__('Actions'))
            ->icon('heroicon-o-ellipsis-vertical')
            ->button();
    }

    /**
     * @return array<int, Action>
     */
    private static function resolveActions(): array
    {
        return [
            self::resolveAmbiguousBankMatchAction(),
            self::postEmiOverpaymentRefundAction(),
            self::acceptOverrideAction(),
            self::resolveAction(),
            self::writeOffAction(),
        ];
    }

    /**
     * @return array<int, Action>
     */
    private static function ledgerActions(): array
    {
        return [
            self::postCorrectionEntryAction(),
            self::postCashCorrectionAction(),
            self::reverseTransactionAction(),
            self::customJournalAction(),
        ];
    }

    /**
     * @return array<int, Action>
     */
    private static function workflowActions(): array
    {
        return [
            self::retryAutoResolveAction(),
            self::assignAction(),
            self::reclassifyAction(),
            self::escalateAction(),
        ];
    }

    public static function deleteAction(): DeleteAction
    {
        return DeleteAction::make()
            ->modalHeading(__('Delete reconciliation exception'))
            ->modalDescription(__('This removes the exception record only. It does not reverse ledger entries or undo resolutions already applied.'));
    }

    public static function deleteBulkAction(): DeleteBulkAction
    {
        return DeleteBulkAction::make()
            ->modalHeading(__('Delete reconciliation exceptions'))
            ->modalDescription(__('This removes the selected exception records only. It does not reverse ledger entries or undo resolutions already applied.'));
    }

    public static function viewAction(): Action
    {
        return Action::make('viewException')
            ->label(__('Open'))
            ->icon('heroicon-o-document-magnifying-glass')
            ->modalHeading(fn (ReconciliationException $record): string => ReconciliationExceptionPresenter::title($record))
            ->modalDescription(fn (ReconciliationException $record): string => ReconciliationExceptionPresenter::summary($record))
            ->modalWidth('3xl')
            ->schema(fn (ReconciliationException $record): array => [
                Section::make(__('Summary'))
                    ->columns(2)
                    ->schema([
                        TextEntry::make('exception_code')
                            ->label(__('Code'))
                            ->badge()
                            ->color('gray'),
                        TextEntry::make('domain')
                            ->label(__('Area'))
                            ->formatStateUsing(fn (string $state): string => ReconciliationExceptionPresenter::domainLabel($state))
                            ->badge(),
                        TextEntry::make('severity')
                            ->label(__('Severity'))
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'critical' => 'danger',
                                'high' => 'warning',
                                'medium' => 'info',
                                'low' => 'gray',
                                default => 'gray',
                            }),
                        TextEntry::make('status')
                            ->label(__('Status'))
                            ->badge(),
                        TextEntry::make('amount_delta')
                            ->label(__('Amount delta'))
                            ->formatStateUsing(fn (?string $state): string => filled($state)
                                ? (MoneyDisplay::format((float) $state) ?? '—')
                                : '—'),
                        TextEntry::make('assignee.name')
                            ->label(__('Assigned to'))
                            ->placeholder(__('Unassigned')),
                        TextEntry::make('raised_at')
                            ->dateTime()
                            ->label(__('Raised')),
                        TextEntry::make('sla_deadline')
                            ->dateTime()
                            ->label(__('SLA deadline'))
                            ->placeholder(__('—')),
                    ]),
                Placeholder::make('recommended_action')
                    ->label(__('Suggested next step'))
                    ->content(fn (ReconciliationException $record): string => ReconciliationExceptionPresenter::recommendedAction($record))
                    ->columnSpanFull(),
                Placeholder::make('context')
                    ->label(__('Related records'))
                    ->content(fn (ReconciliationException $record): Htmlable => ReconciliationExceptionPresenter::contextHtml($record))
                    ->columnSpanFull(),
                Section::make(__('Diagnostics'))
                    ->collapsed()
                    ->schema([
                        TextEntry::make('exception_type')
                            ->label(__('Discrepancy type'))
                            ->placeholder(__('—')),
                        TextEntry::make('auto_resolve_reason')
                            ->label(__('Auto-resolve'))
                            ->placeholder(__('—'))
                            ->columnSpanFull(),
                        TextEntry::make('resolution_notes')
                            ->label(__('Resolution notes'))
                            ->placeholder(__('—'))
                            ->columnSpanFull()
                            ->visible(fn (ReconciliationException $record): bool => filled($record->resolution_notes)),
                    ]),
            ])
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('Close'));
    }

    public static function retryAutoResolveAction(): Action
    {
        return Action::make('retryAutoResolve')
            ->label(__('Retry auto-resolve'))
            ->icon('heroicon-o-arrow-path')
            ->color('info')
            ->visible(fn (ReconciliationException $record): bool => self::isActionable($record))
            ->requiresConfirmation()
            ->action(function (ReconciliationException $record, ReconciliationResolutionService $resolver): void {
                if ($resolver->retryAutoResolve($record)) {
                    Notification::make()->title(__('Auto-resolved'))->success()->send();

                    return;
                }

                Notification::make()
                    ->title(__('Auto-resolve did not apply'))
                    ->body(__('The exception remains open. Use another resolution action or adjust underlying data.'))
                    ->warning()
                    ->send();
            });
    }

    public static function assignAction(): Action
    {
        return Action::make('assign')
            ->label(__('Assign'))
            ->icon('heroicon-o-user-plus')
            ->visible(fn (ReconciliationException $record): bool => self::isActionable($record))
            ->schema([
                Select::make('assigned_to')
                    ->label(__('Assign to'))
                    ->options(fn (): array => User::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->nullable()
                    ->default(fn (): ?int => Auth::guard('tenant')->id()),
            ])
            ->action(function (ReconciliationException $record, Action $action, array $data, ReconciliationResolutionService $resolver): void {
                $resolver->assignTo($record, filled($data['assigned_to'] ?? null) ? (int) $data['assigned_to'] : null);
                Notification::make()->title(__('Assignee updated'))->success()->send();
            });
    }

    public static function reclassifyAction(): Action
    {
        return Action::make('reclassify')
            ->label(__('Reclassify'))
            ->icon('heroicon-o-tag')
            ->visible(fn (ReconciliationException $record): bool => self::isActionable($record))
            ->schema([
                Select::make('exception_type')
                    ->label(__('Discrepancy type'))
                    ->options(Lang::transOptions([
                        'timing_difference' => __('Timing difference'),
                        'amount_mismatch' => __('Amount mismatch'),
                        'missing_entry' => __('Missing entry'),
                        'duplicate_entry' => __('Duplicate entry'),
                        'status_mismatch' => __('Status mismatch'),
                    ]))
                    ->required(),
                Textarea::make('notes')
                    ->label(__('Notes'))
                    ->rows(3)
                    ->required(),
            ])
            ->action(function (ReconciliationException $record, Action $action, array $data, ReconciliationResolutionService $resolver): void {
                $resolver->reclassify($record, (string) $data['exception_type'], (string) $data['notes']);
                Notification::make()->title(__('Exception reclassified'))->success()->send();
            });
    }

    public static function escalateAction(): Action
    {
        return Action::make('escalate')
            ->label(__('Escalate'))
            ->icon('heroicon-o-arrow-up-circle')
            ->color('warning')
            ->visible(fn (ReconciliationException $record): bool => self::isActionable($record))
            ->schema([
                Textarea::make('reason')
                    ->label(__('Escalation reason'))
                    ->required()
                    ->rows(3),
            ])
            ->action(function (ReconciliationException $record, Action $action, array $data, ReconciliationResolutionService $resolver): void {
                $resolver->escalate($record, (string) $data['reason']);
                Notification::make()->title(__('Exception escalated'))->success()->send();
            });
    }

    public static function writeOffAction(): Action
    {
        return Action::make('writeOff')
            ->label(__('Write off'))
            ->icon('heroicon-o-document-minus')
            ->color('gray')
            ->visible(fn (ReconciliationException $record): bool => self::isActionable($record)
                && in_array($record->severity, ['low', 'medium'], true))
            ->requiresConfirmation()
            ->schema([
                Textarea::make('reason')
                    ->label(__('Write-off reason'))
                    ->required()
                    ->rows(3),
            ])
            ->action(function (ReconciliationException $record, Action $action, array $data, ReconciliationResolutionService $resolver): void {
                try {
                    $resolver->writeOff($record, (string) $data['reason']);
                    Notification::make()->title(__('Exception written off'))->success()->send();
                } catch (\InvalidArgumentException $e) {
                    ActionModalFailure::present($action, $e->getMessage());
                }
            });
    }

    public static function acceptOverrideAction(): Action
    {
        return Action::make('acceptOverride')
            ->label(fn (ReconciliationException $record): string => self::isTierBoundaryDispute($record)
                ? __('Accept tier judgment')
                : __('Accept without correction'))
            ->icon('heroicon-o-hand-thumb-up')
            ->color('warning')
            ->visible(fn (ReconciliationException $record): bool => self::isActionable($record))
            ->requiresConfirmation()
            ->modalDescription(fn (ReconciliationException $record): string => self::isTierBoundaryDispute($record)
                ? __('Supervisor sign-off for a tier boundary dispute. Document why the assessed tier or fee is accepted without a corrective entry.')
                : __('Supervisor sign-off closes the exception without a corrective journal entry. Use only when the variance is explained and accepted.'))
            ->schema([
                Textarea::make('reason')
                    ->label(__('Sign-off reason'))
                    ->required()
                    ->rows(4),
            ])
            ->action(function (ReconciliationException $record, Action $action, array $data, ReconciliationResolutionService $resolver): void {
                try {
                    $resolver->acceptOverride($record, (string) $data['reason']);
                    Notification::make()->title(__('Exception accepted'))->success()->send();
                } catch (\InvalidArgumentException $e) {
                    ActionModalFailure::present($action, $e->getMessage());
                }
            });
    }

    public static function customJournalAction(): Action
    {
        return Action::make('customJournal')
            ->label(__('Custom journal'))
            ->icon('heroicon-o-table-cells')
            ->color('primary')
            ->visible(fn (ReconciliationException $record): bool => self::isActionable($record)
                && (bool) Auth::guard('tenant')->user()?->is_admin)
            ->modalHeading(__('Post custom journal'))
            ->modalDescription(__('Build a balanced multi-leg entry linked to this exception. Debits must equal credits.'))
            ->modalWidth('3xl')
            ->fillForm(fn (ReconciliationException $record): array => ReconciliationJournalComposerSchema::defaultFormState($record))
            ->schema(fn (ReconciliationException $record): array => ReconciliationJournalComposerSchema::schema($record))
            ->requiresConfirmation()
            ->action(function (ReconciliationException $record, Action $action, array $data, ReconciliationResolutionService $resolver): void {
                try {
                    $resolver->postCorrection($record, 'custom_journal', $data);
                    Notification::make()
                        ->title(__('Journal posted'))
                        ->body(__('Posted :count legs.', ['count' => count($data['legs'] ?? [])]))
                        ->success()
                        ->send();
                } catch (\InvalidArgumentException $e) {
                    ActionModalFailure::present($action, $e->getMessage());
                }
            });
    }

    public static function postCorrectionEntryAction(): Action
    {
        return Action::make('postCorrectionEntry')
            ->label(__('Post correction entry'))
            ->icon('heroicon-o-document-plus')
            ->color('primary')
            ->visible(fn (ReconciliationException $record): bool => self::isActionable($record))
            ->schema(fn (ReconciliationException $record): array => [
                Select::make('correction_type')
                    ->label(__('Correction type'))
                    ->options(Lang::transOptions([
                        'member_cash_credit' => __('Credit member cash'),
                        'member_cash_debit' => __('Debit member cash'),
                        'member_fund_principal' => __('Post contribution fund legs (master + member)'),
                        'late_fee_tier' => __('Re-apply correct late fee tier'),
                        'emi_overpayment_refund' => __('EMI overpayment refund'),
                    ]))
                    ->required()
                    ->live(),
                TextInput::make('member_id')
                    ->label(__('Member ID'))
                    ->numeric()
                    ->visible(fn ($get): bool => str_starts_with((string) $get('correction_type'), 'member_cash_'))
                    ->default($record->affected_entities['member_id'] ?? null),
                TextInput::make('contribution_id')
                    ->label(__('Contribution ID'))
                    ->numeric()
                    ->visible(fn ($get): bool => in_array($get('correction_type'), ['member_fund_principal', 'late_fee_tier'], true))
                    ->default($record->affected_entities['contribution_id'] ?? null),
                TextInput::make('loan_id')
                    ->label(__('Loan ID'))
                    ->numeric()
                    ->visible(fn ($get): bool => $get('correction_type') === 'emi_overpayment_refund')
                    ->default($record->affected_entities['loan_id'] ?? null),
                TextInput::make('amount')
                    ->label(__('Amount'))
                    ->numeric()
                    ->minValue(0.01)
                    ->visible(fn ($get): bool => in_array($get('correction_type'), [
                        'member_cash_credit',
                        'member_cash_debit',
                        'emi_overpayment_refund',
                    ], true))
                    ->default(isset($record->amount_delta) ? abs((float) $record->amount_delta) : null),
                Textarea::make('reason')
                    ->label(__('Reason'))
                    ->required()
                    ->rows(3),
            ])
            ->requiresConfirmation()
            ->action(function (ReconciliationException $record, Action $action, array $data, ReconciliationResolutionService $resolver): void {
                try {
                    $resolver->postCorrection($record, (string) $data['correction_type'], $data);
                    Notification::make()->title(__('Correction posted'))->success()->send();
                } catch (\InvalidArgumentException $e) {
                    ActionModalFailure::present($action, $e->getMessage());
                }
            });
    }

    public static function reverseTransactionAction(): Action
    {
        return Action::make('reverseTransaction')
            ->label(__('Reverse transaction'))
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('danger')
            ->visible(fn (ReconciliationException $record): bool => self::isActionable($record)
                && filled($record->affected_entities['transaction_id'] ?? null))
            ->schema([
                TextInput::make('transaction_id')
                    ->label(__('Transaction ID'))
                    ->numeric()
                    ->required()
                    ->default(fn (ReconciliationException $record): ?int => isset($record->affected_entities['transaction_id'])
                        ? (int) $record->affected_entities['transaction_id']
                        : null),
                Toggle::make('full_source')
                    ->label(__('Reverse all legs for source reference'))
                    ->default(false),
                Textarea::make('reason')
                    ->label(__('Reason'))
                    ->required()
                    ->rows(3),
            ])
            ->requiresConfirmation()
            ->action(function (ReconciliationException $record, Action $action, array $data, ReconciliationResolutionService $resolver): void {
                try {
                    $resolver->reverseTransaction(
                        $record,
                        (int) $data['transaction_id'],
                        (string) $data['reason'],
                        (bool) ($data['full_source'] ?? false),
                    );
                    Notification::make()->title(__('Transaction reversed'))->success()->send();
                } catch (\InvalidArgumentException $e) {
                    ActionModalFailure::present($action, $e->getMessage());
                }
            });
    }

    public static function postCashCorrectionAction(): Action
    {
        return Action::make('postCashCorrection')
            ->label(__('Post cash correction'))
            ->icon('heroicon-o-banknotes')
            ->visible(fn (ReconciliationException $record): bool => self::isActionable($record)
                && filled($record->affected_entities['member_id'] ?? null))
            ->schema([
                TextInput::make('member_id')
                    ->label(__('Member ID'))
                    ->numeric()
                    ->required()
                    ->default(fn (ReconciliationException $record): ?int => isset($record->affected_entities['member_id'])
                        ? (int) $record->affected_entities['member_id']
                        : null),
                Select::make('direction')
                    ->label(__('Direction'))
                    ->options(Lang::transOptions([
                        'credit' => __('Credit member cash'),
                        'debit' => __('Debit member cash'),
                    ]))
                    ->required(),
                TextInput::make('amount')
                    ->label(__('Amount'))
                    ->numeric()
                    ->required()
                    ->minValue(0.01),
                Textarea::make('reason')
                    ->label(__('Reason'))
                    ->required()
                    ->rows(3),
            ])
            ->requiresConfirmation()
            ->action(function (ReconciliationException $record, Action $action, array $data, ReconciliationResolutionService $resolver): void {
                try {
                    $resolver->postMemberCashCorrection(
                        $record,
                        (int) $data['member_id'],
                        (string) $data['direction'],
                        (float) $data['amount'],
                        (string) $data['reason'],
                    );
                    Notification::make()->title(__('Correction posted'))->success()->send();
                } catch (\InvalidArgumentException $e) {
                    ActionModalFailure::present($action, $e->getMessage());
                }
            });
    }

    public static function postEmiOverpaymentRefundAction(): Action
    {
        return Action::make('postEmiOverpaymentRefund')
            ->label(__('Refund EMI overpayment'))
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('warning')
            ->visible(fn (ReconciliationException $record): bool => self::isActionable($record)
                && $record->exception_code === 'EMI_OVER_COLLECTION'
                && filled($record->affected_entities['loan_id'] ?? null))
            ->schema([
                TextInput::make('loan_id')
                    ->label(__('Loan ID'))
                    ->numeric()
                    ->required()
                    ->default(fn (ReconciliationException $record): ?int => isset($record->affected_entities['loan_id'])
                        ? (int) $record->affected_entities['loan_id']
                        : null),
                TextInput::make('amount')
                    ->label(__('Refund amount'))
                    ->numeric()
                    ->required()
                    ->minValue(0.01)
                    ->default(fn (ReconciliationException $record): ?float => isset($record->amount_delta)
                        ? abs((float) $record->amount_delta)
                        : null),
                Textarea::make('reason')
                    ->label(__('Reason'))
                    ->required()
                    ->rows(3),
            ])
            ->requiresConfirmation()
            ->action(function (ReconciliationException $record, Action $action, array $data, ReconciliationResolutionService $resolver): void {
                try {
                    $resolver->postEmiOverpaymentRefund(
                        $record,
                        (int) $data['loan_id'],
                        (float) $data['amount'],
                        (string) $data['reason'],
                    );
                    Notification::make()->title(__('EMI overpayment refunded'))->success()->send();
                } catch (\InvalidArgumentException $e) {
                    ActionModalFailure::present($action, $e->getMessage());
                }
            });
    }

    public static function resolveAmbiguousBankMatchAction(): Action
    {
        return Action::make('resolveAmbiguousBankMatch')
            ->label(__('Resolve bank match'))
            ->icon('heroicon-o-link')
            ->visible(fn (ReconciliationException $record): bool => self::isActionable($record)
                && $record->exception_code === 'RECON_AMBIGUOUS_MATCH')
            ->schema([
                TextInput::make('imported_bank_transaction_id')
                    ->label(__('Imported bank line ID'))
                    ->numeric()
                    ->required()
                    ->default(fn (ReconciliationException $record): ?int => isset($record->affected_entities['imported_bank_transaction_id'])
                        ? (int) $record->affected_entities['imported_bank_transaction_id']
                        : null),
                Select::make('uncleared_bank_transaction_id')
                    ->label(__('Pending cash entry'))
                    ->options(function (ReconciliationException $record): array {
                        $ids = $record->affected_entities['candidate_ids'] ?? [];

                        if (! is_array($ids) || $ids === []) {
                            return [];
                        }

                        return BankTransaction::query()
                            ->whereIn('id', $ids)
                            ->get()
                            ->mapWithKeys(fn ($txn): array => [
                                $txn->id => __(':id — :amount (:date)', [
                                    'id' => $txn->id,
                                    'amount' => number_format((float) $txn->amount, 2),
                                    'date' => $txn->transaction_date?->format('Y-m-d') ?? '—',
                                ]),
                            ])
                            ->all();
                    })
                    ->required()
                    ->searchable(),
                Textarea::make('notes')
                    ->label(__('Resolution notes'))
                    ->required()
                    ->rows(3),
            ])
            ->action(function (ReconciliationException $record, Action $action, array $data, ReconciliationResolutionService $resolver): void {
                try {
                    $resolver->resolveAmbiguousBankMatch(
                        $record,
                        (int) $data['imported_bank_transaction_id'],
                        (int) $data['uncleared_bank_transaction_id'],
                        (string) $data['notes'],
                    );
                    Notification::make()->title(__('Bank match cleared'))->success()->send();
                } catch (\InvalidArgumentException $e) {
                    ActionModalFailure::present($action, $e->getMessage());
                }
            });
    }

    public static function resolveAction(): Action
    {
        return Action::make('resolve')
            ->label(__('Resolve'))
            ->icon('heroicon-o-check')
            ->color('success')
            ->visible(fn (ReconciliationException $record): bool => self::isActionable($record))
            ->schema([
                Textarea::make('resolution_notes')
                    ->label(__('Resolution notes'))
                    ->rows(3)
                    ->required(),
            ])
            ->action(function (ReconciliationException $record, Action $action, array $data, ReconciliationResolutionService $resolver): void {
                $resolver->resolveManually($record, (string) $data['resolution_notes']);
                Notification::make()->title(__('Exception resolved'))->success()->send();
            });
    }

    /**
     * @return list<string>
     */
    protected static function tierBoundaryExceptionCodes(): array
    {
        return [
            'FEE_WRONG_TIER',
            'REPLACEMENT_PRIOR_TIER_NOT_REVERSED',
        ];
    }

    protected static function isTierBoundaryDispute(ReconciliationException $record): bool
    {
        return in_array($record->exception_code, self::tierBoundaryExceptionCodes(), true);
    }

    protected static function isActionable(ReconciliationException $record): bool
    {
        return ReconciliationExceptionPresenter::isActionable($record);
    }
}
