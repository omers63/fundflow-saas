<?php

declare(strict_types=1);

namespace App\Support\Notifications;

use App\Models\Tenant\FundPosting;
use App\Services\FundPostingSettlementSummary;
use App\Support\Insights\InsightFormatter;

final class FundPostingNotificationFormatter
{
    /**
     * @param  list<array{label: string, value: string, bidi?: bool, emphasis?: bool}>  $rows
     */
    public static function renderDetails(array $rows): string
    {
        if ($rows === []) {
            return '';
        }

        $items = '';

        foreach ($rows as $row) {
            $value = self::formatValue(
                (string) $row['value'],
                (bool) ($row['bidi'] ?? false),
                (bool) ($row['emphasis'] ?? false),
            );

            $items .= '<div class="ff-notification-details__row">'
                .'<dt class="ff-notification-details__label">'.e($row['label']).'</dt>'
                .'<dd class="ff-notification-details__value">'.$value.'</dd>'
                .'</div>';
        }

        return '<dl class="ff-notification-details">'.$items.'</dl>';
    }

    public static function renderSection(string $heading, string $content): string
    {
        if ($content === '') {
            return '';
        }

        return '<section class="ff-notification-section">'
            .'<p class="ff-notification-section__heading">'.e($heading).'</p>'
            .$content
            .'</section>';
    }

    public static function renderLead(string $text): string
    {
        return '<p class="ff-notification-lead">'.e($text).'</p>';
    }

    public static function adminNewRequestBody(FundPosting $posting): string
    {
        $posting->loadMissing('member');

        $html = self::renderLead(__('A new deposit request was submitted.'));
        $html .= self::renderSection(
            __('Request details'),
            self::renderDetails(self::depositDetailRows($posting, includeMember: true)),
        );

        return $html;
    }

    public static function memberAcceptedBody(
        FundPosting $posting,
        ?FundPostingSettlementSummary $settlement,
    ): string {
        $html = self::renderSection(
            __('Deposit'),
            self::renderDetails(self::depositDetailRows($posting)),
        );

        if ($settlement !== null) {
            $html .= self::renderSection(
                __('Settlement'),
                self::renderDetails(self::settlementRows($settlement)),
            );
        }

        if (filled($posting->admin_remarks)) {
            $html .= self::renderSection(
                __('Admin note'),
                self::renderDetails([
                    [
                        'label' => __('Note'),
                        'value' => (string) $posting->admin_remarks,
                        'bidi' => true,
                    ],
                ]),
            );
        }

        return $html;
    }

    public static function memberRejectedBody(FundPosting $posting): string
    {
        $html = self::renderSection(
            __('Deposit'),
            self::renderDetails(self::depositDetailRows($posting)),
        );

        $reason = filled($posting->admin_remarks)
            ? (string) $posting->admin_remarks
            : __('No reason was provided.');

        $html .= self::renderSection(
            __('Rejection'),
            self::renderDetails([
                [
                    'label' => __('Reason'),
                    'value' => $reason,
                    'bidi' => true,
                ],
            ]),
        );

        return $html;
    }

    public static function plainTextFromRows(array $rows): string
    {
        return collect($rows)
            ->map(fn (array $row): string => $row['label'].': '.$row['value'])
            ->implode("\n");
    }

    public static function plainTextDepositDetails(FundPosting $posting, bool $includeMember = false): string
    {
        return self::plainTextFromRows(self::depositDetailRows($posting, $includeMember));
    }

    /**
     * @return list<array{label: string, value: string, bidi?: bool, emphasis?: bool}>
     */
    public static function depositDetailRows(FundPosting $posting, bool $includeMember = false): array
    {
        $posting->loadMissing('member');

        $rows = [];

        if ($includeMember) {
            $rows[] = [
                'label' => __('Member'),
                'value' => (string) $posting->member->name,
                'bidi' => true,
            ];

            if (filled($posting->member->member_number)) {
                $rows[] = [
                    'label' => __('Member number'),
                    'value' => (string) $posting->member->member_number,
                ];
            }
        }

        $rows[] = [
            'label' => __('Amount'),
            'value' => InsightFormatter::money((float) $posting->amount),
            'emphasis' => true,
        ];

        $rows[] = [
            'label' => __('Date'),
            'value' => $posting->posting_date->translatedFormat('j M Y'),
        ];

        if (filled($posting->reference)) {
            $rows[] = [
                'label' => __('Reference'),
                'value' => (string) $posting->reference,
                'bidi' => true,
            ];
        }

        if (filled($posting->comments)) {
            $rows[] = [
                'label' => __('Your note'),
                'value' => (string) $posting->comments,
                'bidi' => true,
            ];
        }

        $rows[] = [
            'label' => __('Request #'),
            'value' => (string) $posting->id,
        ];

        return $rows;
    }

    /**
     * @return list<array{label: string, value: string, bidi?: bool, emphasis?: bool}>
     */
    public static function settlementRows(FundPostingSettlementSummary $settlement): array
    {
        $rows = [];

        if ($settlement->hasSettlement()) {
            $rows[] = [
                'label' => __('Applied from deposit'),
                'value' => InsightFormatter::money($settlement->totalApplied()),
                'emphasis' => true,
            ];

            if ($settlement->contributionsApplied > 0.00001) {
                $rows[] = [
                    'label' => __('Contributions'),
                    'value' => InsightFormatter::money($settlement->contributionsApplied),
                ];
            }

            if ($settlement->loanInstallmentsApplied > 0.00001) {
                $rows[] = [
                    'label' => __('Loan repayments'),
                    'value' => InsightFormatter::money($settlement->loanInstallmentsApplied),
                ];
            }
        } else {
            $rows[] = [
                'label' => __('Auto-settlement'),
                'value' => __('None applied'),
            ];
        }

        $rows[] = [
            'label' => __('Cash balance now'),
            'value' => InsightFormatter::money($settlement->remainingCash),
            'emphasis' => true,
        ];

        return $rows;
    }

    protected static function formatValue(string $value, bool $bidi, bool $emphasis): string
    {
        $classes = [];

        if ($bidi) {
            $classes[] = 'ff-notification-bidi';
        }

        if ($emphasis) {
            $classes[] = 'ff-notification-emphasis';
        }

        $attributes = $classes !== []
            ? ' class="'.implode(' ', $classes).'"'
            : '';

        if ($bidi) {
            $attributes .= ' dir="auto"';
        }

        return '<span'.$attributes.'>'.e($value).'</span>';
    }
}
