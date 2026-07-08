<?php

namespace App\Filament\Support\ViewActions;

use App\Filament\Member\Support\MemberPortalViewModal;
use App\Filament\Support\MoneyDisplay;
use App\Filament\Support\TableRecordActionGroups;
use App\Models\Tenant\FundPosting;
use App\Models\Tenant\Setting;
use App\Support\BusinessDayDisplay;
use App\Support\MemberDateDisplay;
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
            'submitted_at_display' => BusinessDayDisplay::formatDateTime($record->created_at),
            'reviewed_at_display' => $record->reviewed_at?->format('M j, Y g:i A'),
            'reviewer_name' => $record->reviewer?->name ?: __('—'),
            'attachment_url' => $record->attachment
                ? self::attachmentUrl($record->attachment)
                : null,
            'attachment_is_image' => $record->attachment
                && (bool) preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $record->attachment),
        ];
    }

    public static function confirmationSummary(FundPosting $record): string
    {
        $data = self::formatRecordData($record);

        $lines = [
            __('Member: :name', ['name' => $data['member_name']]),
            __('Amount: :amount', ['amount' => $data['amount_display']]),
            __('Date: :date', ['date' => $data['posting_date_display']]),
        ];

        if ($data['reference_display'] !== __('—')) {
            $lines[] = __('Reference: :reference', ['reference' => $data['reference_display']]);
        }

        if ($data['comments_display'] !== __('—')) {
            $lines[] = __('Comments: :comments', ['comments' => $data['comments_display']]);
        }

        if (filled($data['attachment_url'])) {
            $lines[] = __('Receipt attached — use View to preview.');
        }

        return implode("\n\n", $lines);
    }

    public static function make(): ViewAction
    {
        return ViewAction::make()
            ->modalWidth('lg')
            ->modalHeading(fn (FundPosting $record): string => __('Deposit — :name', [
                'name' => $record->member->name,
            ]))
            ->mutateRecordDataUsing(function (array $data, FundPosting $record): array {
                return self::formatRecordData($record);
            })
            ->schema(self::schema());
    }

    public static function makeForMemberPortal(): ViewAction
    {
        return MemberPortalViewModal::apply(
            ViewAction::make()
                ->modalHeading(fn (FundPosting $record): string => __('Deposit request'))
                ->modalContent(fn (FundPosting $record) => MemberPortalViewModal::content(
                    self::memberPortalSections($record),
                )),
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function memberPortalSections(FundPosting $record): array
    {
        $data = self::formatRecordData($record);

        $statusChip = match ($record->status) {
            'pending' => ['chip' => $data['status_display'], 'variant' => 'amber'],
            'accepted' => ['chip' => $data['status_display'], 'variant' => 'green'],
            'rejected' => ['chip' => $data['status_display'], 'variant' => 'red'],
            default => ['chip' => $data['status_display'], 'variant' => 'gray'],
        };

        $sections = [
            [
                'hero' => [
                    'label' => __('Deposit request'),
                    'amount' => $data['amount_display'],
                    'subtitle' => MemberDateDisplay::format($record->posting_date, 'M j, Y'),
                    'chip' => $statusChip['chip'],
                    'chipVariant' => $statusChip['variant'],
                ],
            ],
            [
                'title' => __('Posting details'),
                'columns' => 3,
                'items' => [
                    ['label' => __('Posting date'), 'value' => MemberDateDisplay::format($record->posting_date, 'M j, Y') ?? $data['posting_date_display']],
                    ['label' => __('Reference'), 'value' => $data['reference_display']],
                    ['label' => __('Submitted'), 'value' => $data['submitted_at_display']],
                ],
            ],
        ];

        if ($data['comments_display'] !== __('—')) {
            $sections[] = [
                'title' => __('Comments'),
                'prose' => $data['comments_display'],
            ];
        }

        if (in_array($record->status, ['accepted', 'rejected'], true)) {
            $sections[] = [
                'title' => __('Review'),
                'columns' => 3,
                'items' => [
                    ['label' => __('Reviewed by'), 'value' => $data['reviewer_name']],
                    ['label' => __('Reviewed at'), 'value' => MemberDateDisplay::format($record->reviewed_at, 'M j, Y g:i A') ?? $data['reviewed_at_display']],
                ],
            ];

            if ($data['admin_remarks_display'] !== __('—')) {
                $sections[] = [
                    'title' => __('Admin remarks'),
                    'prose' => $data['admin_remarks_display'],
                ];
            }
        }

        if (filled($data['attachment_url'])) {
            $sections[] = [
                'title' => __('Receipt'),
                'view' => 'filament.member.partials.fund-posting-receipt',
                'viewData' => [
                    'url' => $data['attachment_url'],
                    'isImage' => $data['attachment_is_image'],
                ],
            ];
        }

        return $sections;
    }

    /**
     * @return array<int, Section>
     */
    public static function schema(): array
    {
        return self::buildSections(readOnly: false);
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
        return TableRecordActionGroups::apply($table, [self::make()]);
    }
}
