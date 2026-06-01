<?php

declare(strict_types=1);

namespace App\Notifications\Tenant\Concerns;

use App\Models\Tenant\FundPosting;
use App\Services\FundPostingSettlementSummary;
use App\Support\Notifications\FundPostingNotificationFormatter;

trait BuildsFundPostingDatabaseMessage
{
    protected function fundPostingDatabaseBody(
        FundPosting $posting,
        ?FundPostingSettlementSummary $settlement = null,
        string $context = 'member',
    ): string {
        return match ($context) {
            'admin_new' => FundPostingNotificationFormatter::adminNewRequestBody($posting),
            'accepted' => FundPostingNotificationFormatter::memberAcceptedBody($posting, $settlement),
            'rejected' => FundPostingNotificationFormatter::memberRejectedBody($posting),
            default => FundPostingNotificationFormatter::memberAcceptedBody($posting, $settlement),
        };
    }

    protected function fundPostingBody(FundPosting $posting, ?FundPostingSettlementSummary $settlement = null): string
    {
        $lines = FundPostingNotificationFormatter::depositDetailRows($posting);

        if ($settlement !== null) {
            $lines = [
                ...$lines,
                ...FundPostingNotificationFormatter::settlementRows($settlement),
            ];
        }

        if (filled($posting->admin_remarks)) {
            $lines[] = [
                'label' => __('Admin note'),
                'value' => (string) $posting->admin_remarks,
            ];
        }

        return FundPostingNotificationFormatter::plainTextFromRows($lines);
    }
}
