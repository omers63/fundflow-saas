<?php

namespace App\Filament\Support\ViewActions;

use App\Filament\Support\MoneyDisplay;
use App\Models\Tenant\FundPosting;
use App\Models\Tenant\Setting;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Tables\Table;

final class ViewFundPostingAction
{
    public static function attachmentUrl(string $path): string
    {
        return url('/tenancy/assets/'.$path);
    }

    /**
     * @return array<string, mixed>
     */
    public static function formatRecordData(FundPosting $record): array
    {
        $record->loadMissing(['member', 'reviewer']);

        $currency = Setting::get('general', 'currency', 'USD');

        return [
            ...$record->attributesToArray(),
            'member_name' => $record->member->name,
            'posting_date_display' => $record->posting_date->format('M j, Y'),
            'amount_display' => MoneyDisplay::format((float) $record->amount, $currency),
            'status_display' => match ($record->status) {
                'pending' => __('Pending'),
                'accepted' => __('Accepted'),
                'rejected' => __('Rejected'),
                default => ucfirst($record->status),
            },
            'reference_display' => $record->reference ?: __('—'),
            'comments_display' => $record->comments ?: __('—'),
            'admin_remarks_display' => $record->admin_remarks ?: __('—'),
            'submitted_at_display' => $record->created_at?->format('M j, Y g:i A'),
            'reviewed_at_display' => $record->reviewed_at?->format('M j, Y g:i A'),
            'reviewer_name' => $record->reviewer?->name ?: __('—'),
            'attachment_url' => $record->attachment
                ? self::attachmentUrl($record->attachment)
                : null,
            'attachment_is_image' => $record->attachment
                && (bool) preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $record->attachment),
        ];
    }

    public static function make(): ViewAction
    {
        return ViewAction::make()
            ->modalWidth('2xl')
            ->modalHeading(fn (FundPosting $record): string => __('Deposit — :name', [
                'name' => $record->member->name,
            ]))
            ->mutateRecordDataUsing(function (array $data, FundPosting $record): array {
                return self::formatRecordData($record);
            })
            ->schema(self::schema());
    }

    /**
     * @return array<int, Section>
     */
    public static function schema(): array
    {
        return self::buildSections(readOnly: false);
    }

    /**
     * Read-only detail blocks for accept / reject confirmation modals.
     *
     * @return array<int, Section>
     */
    public static function readOnlyDetailSchema(): array
    {
        return self::buildSections(readOnly: true);
    }

    /**
     * @return array<int, Section>
     */
    private static function buildSections(bool $readOnly): array
    {
        return [
            Section::make(__('Posting details'))
                ->columns(2)
                ->schema(self::applyReadOnly(self::postingDetailFields(), $readOnly)),
            Section::make(__('Review'))
                ->columns(2)
                ->visible(fn (Get $get): bool => in_array($get('status'), ['accepted', 'rejected'], true))
                ->schema(self::applyReadOnly(self::reviewFields(), $readOnly)),
            Section::make(__('Receipt'))
                ->visible(fn (Get $get): bool => filled($get('attachment')))
                ->schema(self::applyReadOnly(self::receiptFields(), $readOnly)),
        ];
    }

    /**
     * @return array<int, TextInput|Textarea>
     */
    private static function postingDetailFields(): array
    {
        return [
            TextInput::make('id')
                ->label(__('Posting ID')),
            TextInput::make('member_name')
                ->label(__('Member')),
            TextInput::make('posting_date_display')
                ->label(__('Posting date')),
            TextInput::make('amount_display')
                ->label(__('Amount')),
            TextInput::make('status_display')
                ->label(__('Status')),
            TextInput::make('submitted_at_display')
                ->label(__('Submitted')),
            TextInput::make('reference_display')
                ->label(__('Reference'))
                ->columnSpanFull(),
            Textarea::make('comments_display')
                ->label(__('Comments'))
                ->placeholder(__('—'))
                ->rows(3)
                ->columnSpanFull(),
        ];
    }

    /**
     * @return array<int, TextInput|Textarea>
     */
    private static function reviewFields(): array
    {
        return [
            TextInput::make('reviewer_name')
                ->label(__('Reviewed by')),
            TextInput::make('reviewed_at_display')
                ->label(__('Reviewed at')),
            Textarea::make('admin_remarks_display')
                ->label(__('Admin remarks'))
                ->placeholder(__('—'))
                ->rows(2)
                ->columnSpanFull(),
        ];
    }

    /**
     * @return array<int, View>
     */
    private static function receiptFields(): array
    {
        return [
            View::make('filament.forms.fund-posting-receipt')
                ->viewData(fn (Get $get): array => [
                    'url' => $get('attachment_url'),
                    'isImage' => (bool) $get('attachment_is_image'),
                ]),
        ];
    }

    /**
     * @param  array<int, Component>  $fields
     * @return array<int, Component>
     */
    private static function applyReadOnly(array $fields, bool $readOnly): array
    {
        if (! $readOnly) {
            return $fields;
        }

        return array_map(
            fn (Component $field): Component => $field->disabled()->dehydrated(false),
            $fields,
        );
    }

    public static function configure(Table $table): Table
    {
        return $table
            ->recordUrl(fn (): ?string => null)
            ->recordAction(ViewAction::getDefaultName());
    }

    /**
     * @param  array<int, Component>  $additionalFields
     * @return array<int, Component>
     */
    public static function modalSchemaWith(array $additionalFields): array
    {
        return [
            ...self::readOnlyDetailSchema(),
            ...$additionalFields,
        ];
    }

    public static function fillFormFromRecord(): \Closure
    {
        return fn (FundPosting $record): array => self::formatRecordData($record);
    }
}
