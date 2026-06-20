<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Support;

use App\Filament\Support\ViewActions\ViewContributionAction as SharedViewContributionAction;
use App\Models\Tenant\Contribution;
use Filament\Actions\ViewAction;

final class ViewContributionAction
{
    public static function make(): ViewAction
    {
        return TenantPortalViewModal::apply(
            ViewAction::make()
                ->modalHeading(fn (Contribution $record): string => __('Contribution — :name', [
                    'name' => $record->member->name,
                ]))
                ->modalContent(fn (Contribution $record) => TenantPortalViewModal::content(
                    self::sections($record),
                )),
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function sections(Contribution $record): array
    {
        $data = SharedViewContributionAction::formatRecordData($record);

        $statusChip = match ($record->status) {
            'posted' => 'green',
            'pending' => 'amber',
            'failed' => 'red',
            default => 'gray',
        };

        $sections = [
            [
                'hero' => [
                    'label' => $data['period_display'],
                    'amount' => $data['amount_display'],
                    'subtitle' => $record->member->name,
                    'chip' => $data['status_display'],
                    'chipVariant' => $statusChip,
                    'chipSecondary' => $data['collection_status_display'],
                    'chipSecondaryVariant' => 'gray',
                ],
            ],
            [
                'title' => __('Contribution'),
                'columns' => 3,
                'items' => [
                    ['label' => __('Member'), 'value' => $data['member_name']],
                    ['label' => __('Period'), 'value' => $data['period_display']],
                    ['label' => __('Amount'), 'value' => $data['amount_display']],
                    ['label' => __('Source'), 'value' => $data['payment_method_display']],
                    ['label' => __('Collection status'), 'value' => $data['collection_status_display']],
                    ['label' => __('Late flag'), 'value' => $data['is_late_display']],
                ],
            ],
            [
                'title' => __('Settlement'),
                'columns' => 3,
                'items' => [
                    ['label' => __('Amount due'), 'value' => $data['amount_due_display']],
                    ['label' => __('Amount collected'), 'value' => $data['amount_collected_display']],
                    ['label' => __('Late fee'), 'value' => $data['late_fee_amount_display']],
                    ['label' => __('Posted at'), 'value' => $data['posted_at_display']],
                    ['label' => __('Paid at'), 'value' => $data['paid_at_display']],
                    ['label' => __('Reference'), 'value' => $data['reference_display']],
                ],
            ],
        ];

        if ($data['notes_display'] !== __('—')) {
            $sections[] = [
                'title' => __('Notes'),
                'prose' => $data['notes_display'],
            ];
        }

        return $sections;
    }
}
