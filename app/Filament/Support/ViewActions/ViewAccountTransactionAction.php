<?php

namespace App\Filament\Support\ViewActions;

use App\Filament\Member\Support\MemberPortalViewModal;
use App\Filament\Support\MoneyDisplay;
use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Support\TableToolbar;
use App\Filament\Tenant\Support\ViewAccountTransactionAction as TenantViewAccountTransactionAction;
use App\Models\Tenant\Setting;
use App\Models\Tenant\Transaction;
use App\Support\LedgerSettings;
use App\Support\MemberDateDisplay;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

final class ViewAccountTransactionAction
{
    public static function make(): ViewAction
    {
        return ViewAction::make()
            ->modalWidth('lg')
            ->modalHeading(fn (Transaction $record): string => filled($record->description)
                ? $record->displayDescription()
                : __('Transaction #:id', ['id' => $record->id]))
            ->mutateRecordDataUsing(function (array $data, Transaction $record): array {
                $record->loadMissing(['account', 'member', 'reference']);

                $currency = Setting::get('general', 'currency', 'USD');

                return [
                    ...$data,
                    'transacted_at_display' => $record->transacted_at instanceof Carbon
                        ? $record->transacted_at->format('M j, Y g:i A')
                        : (string) $record->transacted_at,
                    'signed_amount_display' => MoneyDisplay::format($record->getSignedAmount(), $currency),
                    'balance_after_display' => MoneyDisplay::format((float) $record->balance_after, $currency),
                    'type_display' => Transaction::typeLabel($record->type),
                    'account_name' => $record->ledgerAccountLabel(),
                    'member_tag' => $record->member?->name,
                    'description' => $record->displayDescription(),
                    'reference_summary' => $record->linkedSourceDetail(),
                    'created_at_display' => $record->created_at?->format('M j, Y g:i A'),
                    'updated_at_display' => $record->updated_at?->format('M j, Y g:i A'),
                ];
            })
            ->schema(self::schema());
    }

    public static function makeForMemberPortal(): ViewAction
    {
        return MemberPortalViewModal::apply(
            ViewAction::make()
                ->modalHeading(fn (Transaction $record): string => $record->memberFacingDescription())
                ->modalContent(fn (Transaction $record) => MemberPortalViewModal::content(
                    self::memberPortalSections($record),
                )),
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function memberPortalSections(Transaction $record): array
    {
        $record->loadMissing(['account', 'member', 'reference']);

        $currency = Setting::get('general', 'currency', 'USD');

        $heading = $record->memberFacingDescription();
        $transactedAt = MemberDateDisplay::format($record->transacted_at, 'M j, Y g:i A') ?? __('—');
        $signedAmount = MoneyDisplay::format($record->getSignedAmount(), $currency);
        $balanceAfter = MoneyDisplay::format((float) $record->balance_after, $currency);

        $sections = [
            [
                'hero' => [
                    'label' => $heading,
                    'amount' => $signedAmount,
                    'type' => $record->type,
                    'chip' => $record->memberFacingTypeLabel(),
                    'chipVariant' => $record->type === 'credit' ? 'green' : 'gray',
                    'subtitle' => $record->ledgerAccountLabel(memberFacing: true),
                ],
            ],
            [
                'title' => __('Details'),
                'columns' => 3,
                'items' => array_values(array_filter([
                    ['label' => __('Date'), 'value' => $transactedAt],
                    ['label' => __('Account'), 'value' => $record->ledgerAccountLabel(memberFacing: true)],
                    ['label' => __('Balance after'), 'value' => $balanceAfter],
                    filled($record->member?->name)
                    ? ['label' => __('Member tag'), 'value' => $record->member->name]
                    : null,
                ])),
            ],
        ];

        $referenceSection = self::referenceModalSection($record, memberFacing: true, heading: $heading);

        if ($referenceSection !== null) {
            $sections[] = $referenceSection;
        }

        return $sections;
    }

    /**
     * @return array{title: string, columns: int, items: array<int, array{label: string, value: string}>}|null
     */
    public static function referenceModalSection(Transaction $record, bool $memberFacing, ?string $heading): ?array
    {
        $items = $record->modalReferenceItems($memberFacing);

        if ($heading !== null) {
            $items = array_values(array_filter(
                $items,
                fn (array $item): bool => ($item['label'] ?? '') !== __('Linked source')
                || ($item['value'] ?? '') !== $heading,
            ));
        }

        if ($items === []) {
            return null;
        }

        return [
            'title' => __('Reference'),
            'columns' => 2,
            'items' => $items,
        ];
    }

    /**
     * @return array<int, Section>
     */
    public static function schema(): array
    {
        return [
            Section::make(__('Transaction details'))
                ->columns(2)
                ->schema([
                    TextInput::make('id')
                        ->label(__('Transaction ID')),
                    TextInput::make('transacted_at_display')
                        ->label(__('Date')),
                    TextInput::make('type_display')
                        ->label(__('Type')),
                    TextInput::make('signed_amount_display')
                        ->label(__('Amount')),
                    TextInput::make('balance_after_display')
                        ->label(__('Balance after')),
                    TextInput::make('account_name')
                        ->label(__('Account'))
                        ->placeholder(__('—')),
                    TextInput::make('member_tag')
                        ->label(__('Member tag'))
                        ->placeholder(__('—'))
                        ->visible(fn (?string $state): bool => filled($state)),
                    Textarea::make('description')
                        ->label(__('Description'))
                        ->placeholder(__('—'))
                        ->rows(3)
                        ->columnSpanFull(),
                    Textarea::make('reference_summary')
                        ->label(__('Linked source'))
                        ->placeholder(__('—'))
                        ->rows(2)
                        ->columnSpanFull(),
                    TextInput::make('created_at_display')
                        ->label(__('Created')),
                    TextInput::make('updated_at_display')
                        ->label(__('Updated')),
                ]),
        ];
    }

    public static function configure(Table $table, bool $editable = true, bool $memberPortal = false): Table
    {
        $viewAction = match (true) {
            $memberPortal => self::makeForMemberPortal(),
            Filament::getCurrentPanel()?->getId() === 'tenant' => TenantViewAccountTransactionAction::make(),
            default => self::make(),
        };

        $actions = $editable
            ? [
                EditAccountTransactionAction::make(),
                SplitAccountTransactionAction::make(),
                ReverseAccountTransactionAction::make(),
                DeleteAccountTransactionAction::make(),
                $viewAction
                    ->hidden(fn (): bool => (bool) Auth::guard('tenant')->user()?->is_admin),
            ]
            : [$viewAction];

        $table = TableGrouping::apply($table, TableGrouping::accountTransactions());

        if (! $editable) {
            return TableRecordActionGroups::apply($table, $actions);
        }

        return $table
            ->recordUrl(fn (): ?string => null)
            ->recordActions(TableRecordActionGroups::wrap($actions))
            ->recordAction(function () use ($editable): ?string {
                if (! $editable) {
                    return ViewAction::getDefaultName();
                }

                return Auth::guard('tenant')->user()?->is_admin && LedgerSettings::showEditDelete()
                    ? EditAction::getDefaultName()
                    : ViewAction::getDefaultName();
            });
    }

    /**
     * @return array<int, BulkActionGroup>
     */
    public static function tenantToolbarActions(): array
    {
        return [
            BulkActionGroup::make([
                DeleteAccountTransactionsBulkAction::make(),
                TableToolbar::refreshBulkAction(),
            ]),
        ];
    }
}
