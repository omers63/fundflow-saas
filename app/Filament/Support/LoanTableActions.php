<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Filament\Actions\Action;
use Filament\Actions\BulkAction;

/**
 * @deprecated Use {@see LoanFilamentActions} directly.
 */
final class LoanTableActions
{
    public static function approve(): Action
    {
        return LoanFilamentActions::approve();
    }

    public static function reject(): Action
    {
        return LoanFilamentActions::reject();
    }

    public static function disburse(): Action
    {
        return LoanFilamentActions::disburse();
    }

    public static function payout(): Action
    {
        return LoanFilamentActions::markBankPayout();
    }

    public static function recordRepayment(): Action
    {
        return LoanFilamentActions::applyOpenRepayment();
    }

    public static function cancel(): Action
    {
        return LoanFilamentActions::cancel();
    }

    /**
     * @return array<int, Action>
     */
    public static function workflowActions(): array
    {
        return LoanFilamentActions::workflowActions();
    }

    /**
     * @return array<int, BulkAction>
     */
    public static function bulkActions(): array
    {
        return LoanFilamentActions::bulkActions();
    }
}
