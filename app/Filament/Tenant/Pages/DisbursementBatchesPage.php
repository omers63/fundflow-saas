<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Pages;

use App\Filament\Concerns\TranslatesPageNavigationLabel;
use App\Filament\Pages\Page;
use App\Filament\Support\DateColumnRangeFilter;
use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableHeaderIconAction;
use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Support\TableStandards;
use App\Filament\Tenant\Support\TenantNavigation;
use App\Models\Tenant\DisbursementBatch;
use App\Models\Tenant\OutboundPayment;
use App\Models\Tenant\Setting;
use App\Models\Tenant\User;
use App\Services\Disbursement\DisbursementBatchService;
use App\Services\Disbursement\SarieAckImportService;
use App\Support\DisbursementBatchPermissions;
use App\Support\StepUpGuard;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

class DisbursementBatchesPage extends Page implements HasTable
{
    use InteractsWithTable;
    use TranslatesPageNavigationLabel;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCurrencyDollar;

    protected static string|UnitEnum|null $navigationGroup = TenantNavigation::GROUP_FUND_MANAGEMENT;

    protected static ?string $navigationLabel = 'Disbursement batches';

    protected static ?int $navigationSort = TenantNavigation::SORT_CASH_OUTS + 1;

    protected static ?string $slug = 'disbursement-batches';

    protected static ?string $title = 'Disbursement batches';

    protected string $view = 'filament.tenant.pages.disbursement-batches-page';

    public static function canAccess(): bool
    {
        $user = auth('tenant')->user();

        return $user !== null && Gate::forUser($user)->allows(DisbursementBatchPermissions::VIEW);
    }

    public function getTitle(): string|Htmlable
    {
        return __('Disbursement batches');
    }

    public function getSubheading(): ?string
    {
        return __('Build dual-controlled bank files for pending outbound remittances.');
    }

    public function table(Table $table): Table
    {
        return TableGrouping::apply(
            TableRecordActionGroups::apply(
                $table
                    ->query(DisbursementBatch::query()->withCount('items'))
                    ->columns([
                        TextColumn::make('id')
                            ->label(__('Batch'))
                            ->sortable(),
                        TextColumn::make('status')
                            ->badge()
                            ->formatStateUsing(fn(string $state): string => DisbursementBatch::statusLabels()[$state] ?? ucfirst($state))
                            ->color(fn(string $state): string => match ($state) {
                                DisbursementBatch::STATUS_APPROVED, DisbursementBatch::STATUS_GENERATED => 'success',
                                DisbursementBatch::STATUS_PENDING_APPROVAL => 'warning',
                                DisbursementBatch::STATUS_CLEARED => 'info',
                                DisbursementBatch::STATUS_CANCELLED => 'gray',
                                default => 'gray',
                            }),
                        TextColumn::make('bank_format')
                            ->label(__('Format'))
                            ->formatStateUsing(fn(string $state): string => DisbursementBatch::formatLabels()[$state] ?? $state),
                        TextColumn::make('item_count')
                            ->label(__('Items'))
                            ->numeric()
                            ->sortable(),
                        TextColumn::make('total_amount')
                            ->money(fn(): string => Setting::get('general', 'currency', 'SAR'))
                            ->sortable(),
                        TextColumn::make('creator.name')
                            ->label(__('Builder'))
                            ->placeholder(__('—')),
                        TextColumn::make('approver.name')
                            ->label(__('Approver'))
                            ->placeholder(__('—')),
                        TextColumn::make('file_sha256')
                            ->label(__('File SHA-256'))
                            ->limit(12)
                            ->toggleable(isToggledHiddenByDefault: true)
                            ->placeholder(__('—')),
                        TextColumn::make('created_at')
                            ->dateTime()
                            ->sortable(),
                    ])
                    ->filters([
                        SelectFilter::make('status')
                            ->options(DisbursementBatch::statusLabels()),
                        SelectFilter::make('bank_format')
                            ->label(__('Format'))
                            ->options(DisbursementBatch::formatLabels()),
                        DateColumnRangeFilter::make('created_at', __('Created')),
                    ])
                    ->defaultSort('id', 'desc')
                    ->toolbarActions(TableStandards::defaultToolbarActions()),
                [
                    $this->submitAction(),
                    $this->approveAction(),
                    $this->generateAction(),
                    $this->importAckAction(),
                    $this->downloadAction(),
                ],
            ),
            [
                Group::make('status')
                    ->label(__('Status'))
                    ->titlePrefixedWithLabel(false)
                    ->getTitleFromRecordUsing(fn(DisbursementBatch $record): string => DisbursementBatch::statusLabels()[$record->status] ?? $record->status),
            ],
        );
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            TableHeaderIconAction::apply(
                Action::make('createBatch')
                    ->label(__('New batch'))
                    ->icon('heroicon-o-plus-circle')
                    ->schema([
                        Select::make('bank_format')
                            ->label(__('Bank file format'))
                            ->options(DisbursementBatch::formatLabels())
                            ->default(DisbursementBatch::FORMAT_AL_RAJHI_CSV)
                            ->required(),
                        CheckboxList::make('outbound_payment_ids')
                            ->label(__('Eligible remittances'))
                            ->options(fn(): array => app(DisbursementBatchService::class)
                                ->eligibleOutboundPayments()
                                ->mapWithKeys(fn(OutboundPayment $payment): array => [
                                    $payment->id => sprintf(
                                        '#%d %s — %s (%s)',
                                        $payment->id,
                                        $payment->payee_name,
                                        number_format((float) $payment->amount, 2),
                                        $payment->payee_iban,
                                    ),
                                ])
                                ->all())
                            ->required()
                            ->columns(1),
                    ])
                    ->action(function (array $data): void {
                        /** @var User $user */
                        $user = auth('tenant')->user();

                        if (!Gate::forUser($user)->allows(DisbursementBatchPermissions::CREATE)) {
                            Notification::make()->title(__('Not allowed'))->danger()->send();

                            return;
                        }

                        $service = app(DisbursementBatchService::class);
                        $batch = $service->createDraft($user, (string) $data['bank_format']);
                        $service->addEligibleItems($batch, array_map('intval', $data['outbound_payment_ids'] ?? []));

                        Notification::make()
                            ->title(__('Batch created'))
                            ->body(__('Draft batch #:id is ready to submit for approval.', ['id' => $batch->id]))
                            ->success()
                            ->send();
                    }),
            ),
            TableHeaderIconAction::apply(
                Action::make('confirmStepUp')
                    ->label(__('Confirm step-up'))
                    ->icon('heroicon-o-lock-closed')
                    ->schema([
                        TextInput::make('password')
                            ->label(__('Password'))
                            ->password()
                            ->required(),
                    ])
                    ->action(function (array $data): void {
                        /** @var User $user */
                        $user = auth('tenant')->user();

                        try {
                            app(StepUpGuard::class)->confirm($user, (string) $data['password']);
                        } catch (InvalidArgumentException $e) {
                            Notification::make()->title(__('Step-up failed'))->body($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()
                            ->title(__('Step-up confirmed'))
                            ->body(__('Sensitive actions are unlocked for :minutes minutes.', [
                                'minutes' => app(StepUpGuard::class)->ttlMinutes(),
                            ]))
                            ->success()
                            ->send();
                    }),
            ),
        ];
    }

    private function submitAction(): Action
    {
        return Action::make('submitForApproval')
            ->label(__('Submit for approval'))
            ->icon('heroicon-o-paper-airplane')
            ->visible(fn(DisbursementBatch $record): bool => $record->status === DisbursementBatch::STATUS_DRAFT)
            ->requiresConfirmation()
            ->action(function (DisbursementBatch $record): void {
                try {
                    app(DisbursementBatchService::class)->submitForApproval($record);
                } catch (InvalidArgumentException $e) {
                    Notification::make()->title(__('Could not submit'))->body($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title(__('Submitted for approval'))->success()->send();
            });
    }

    private function approveAction(): Action
    {
        return Action::make('approveBatch')
            ->label(__('Approve'))
            ->icon('heroicon-o-check-badge')
            ->color('success')
            ->visible(fn(DisbursementBatch $record): bool => $record->status === DisbursementBatch::STATUS_PENDING_APPROVAL
                && Gate::allows(DisbursementBatchPermissions::APPROVE))
            ->requiresConfirmation()
            ->action(function (DisbursementBatch $record): void {
                /** @var User $user */
                $user = auth('tenant')->user();

                try {
                    if (!Gate::forUser($user)->allows(DisbursementBatchPermissions::APPROVE)) {
                        throw new InvalidArgumentException(__('You do not have permission to approve disbursement batches.'));
                    }

                    app(StepUpGuard::class)->assertConfirmed();
                    app(DisbursementBatchService::class)->approve($record, $user);
                } catch (InvalidArgumentException $e) {
                    Notification::make()->title(__('Could not approve'))->body($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title(__('Batch approved'))->success()->send();
            });
    }

    private function generateAction(): Action
    {
        return Action::make('generateFile')
            ->label(__('Generate file'))
            ->icon('heroicon-o-document-arrow-down')
            ->visible(fn(DisbursementBatch $record): bool => in_array($record->status, [
                DisbursementBatch::STATUS_APPROVED,
                DisbursementBatch::STATUS_GENERATED,
            ], true))
            ->requiresConfirmation()
            ->action(function (DisbursementBatch $record): void {
                try {
                    $batch = app(DisbursementBatchService::class)->generateFile($record);
                } catch (InvalidArgumentException $e) {
                    Notification::make()->title(__('Could not generate'))->body($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()
                    ->title(__('File generated'))
                    ->body(__('SHA-256: :hash', ['hash' => $batch->file_sha256]))
                    ->success()
                    ->send();
            });
    }

    private function downloadAction(): Action
    {
        return Action::make('downloadFile')
            ->label(__('Download'))
            ->icon('heroicon-o-arrow-down-tray')
            ->visible(fn(DisbursementBatch $record): bool => filled($record->file_disk_path)
                && in_array($record->status, [
                    DisbursementBatch::STATUS_GENERATED,
                    DisbursementBatch::STATUS_ACKED,
                    DisbursementBatch::STATUS_CLEARED,
                ], true))
            ->action(function (DisbursementBatch $record): ?StreamedResponse {
                try {
                    app(StepUpGuard::class)->assertConfirmed();
                    $meta = app(DisbursementBatchService::class)->downloadMeta($record);
                } catch (InvalidArgumentException $e) {
                    Notification::make()->title(__('Could not download'))->body($e->getMessage())->danger()->send();

                    return null;
                }

                return Storage::disk('local')->download($meta['path'], $meta['download_name'], [
                    'Content-Type' => $meta['mime'],
                    'X-File-SHA256' => $meta['sha256'],
                ]);
            });
    }

    private function importAckAction(): Action
    {
        return Action::make('importAck')
            ->label(__('Import bank ack'))
            ->icon(Heroicon::OutlinedDocumentCheck)
            ->visible(fn(DisbursementBatch $record): bool => in_array($record->status, [
                DisbursementBatch::STATUS_GENERATED,
                DisbursementBatch::STATUS_ACKED,
            ], true))
            ->schema([
                Textarea::make('contents')
                    ->label(__('Acknowledgement CSV or pain.002 XML'))
                    ->required()
                    ->rows(8)
                    ->helperText(__('CSV: item_id_or_iban,status(accepted|rejected),reference,reason')),
            ])
            ->action(function (DisbursementBatch $record, array $data): void {
                try {
                    $result = app(SarieAckImportService::class)->import($record, (string) $data['contents']);
                } catch (InvalidArgumentException $e) {
                    Notification::make()->title(__('Ack import failed'))->body($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()
                    ->title(__('Bank acknowledgement imported'))
                    ->body(__('Accepted :a · Rejected :r', [
                        'a' => $result['accepted'],
                        'r' => $result['rejected'],
                    ]))
                    ->success()
                    ->send();

                $this->resetTable();
            });
    }
}
