<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\SmsImportTemplates\Tables;

use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Support\TableToolbar;
use App\Filament\Tenant\Resources\SmsImportTemplates\Pages\CreateSmsImportTemplate;
use App\Support\Lang;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

final class SmsImportTemplatesTable
{
    public static function createAction(bool $fromSettings = false): CreateAction
    {
        return CreateAction::make()
            ->label(__('New SMS template'))
            ->icon(Heroicon::OutlinedChatBubbleBottomCenterText)
            ->url(fn (): string => CreateSmsImportTemplate::getUrl(
                $fromSettings ? ['from' => 'settings'] : [],
            ));
    }

    public static function configure(Table $table, bool $embedInBankWorkspace = false, bool $fromSettings = false, bool $includeCreateHeaderAction = true): Table
    {
        $table = TableGrouping::apply($table
            ->heading($embedInBankWorkspace ? null : __('SMS import templates'))
            ->description($embedInBankWorkspace
                ? __('Maintain SMS parsing patterns and member matching rules for exports.')
                : __('Parse bank alert SMS exports: column mapping, regex extraction, member auto-match, and duplicate rules.'))
            ->columns([
                TextColumn::make('bank_name')
                    ->label(__('Bank'))
                    ->placeholder(__('Any'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                IconColumn::make('is_default')
                    ->label(__('Default'))
                    ->boolean(),
                TextColumn::make('delimiter')
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        ',' => __('Comma'),
                        ';' => __('Semicolon'),
                        "\t" => __('Tab'),
                        '|' => __('Pipe'),
                        default => (string) $state,
                    }),
                IconColumn::make('has_header')
                    ->label(__('Has header'))
                    ->boolean(),
                TextColumn::make('sms_column')
                    ->label(__('SMS col.')),
                TextColumn::make('duplicate_match_fields')
                    ->label(__('Dup. fields'))
                    ->formatStateUsing(fn ($state): string => is_array($state) ? implode(', ', $state) : (string) $state),
            ])
            ->defaultSort('name')
            ->filters([
                TernaryFilter::make('is_default')
                    ->label(__('Default template')),
                SelectFilter::make('default_transaction_type')
                    ->label(__('Default type'))
                    ->options(Lang::transOptions([
                        'credit' => __('Credit'),
                        'debit' => __('Debit'),
                    ])),
                TrashedFilter::make(),
            ])
            ->recordActions(TableRecordActionGroups::wrap([
                EditAction::make(),
                DeleteAction::make(),
                RestoreAction::make(),
                ForceDeleteAction::make(),
            ]))
            ->toolbarActions([
                BulkActionGroup::make([
                    TableToolbar::refreshBulkAction(),
                ]),
            ]), TableGrouping::smsImportTemplates());

        if ($includeCreateHeaderAction) {
            $table->headerActions([
                self::createAction($fromSettings),
            ]);
        }

        return $table;
    }
}
