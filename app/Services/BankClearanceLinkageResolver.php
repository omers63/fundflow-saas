<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\FundPosting;

final class BankClearanceLinkageResolver
{
    /**
     * @return array<string, int|string|null>
     */
    public function forFundPosting(BankTransaction $uncleared): array
    {
        $posting = $uncleared->fund_posting_id !== null
            ? FundPosting::query()->find($uncleared->fund_posting_id)
            : null;

        return [
            'fund_posting_id' => $uncleared->fund_posting_id,
            'membership_application_id' => $uncleared->membership_application_id,
            'status' => 'posted',
            'member_id' => $posting?->member_id ?? $uncleared->member_id,
        ];
    }

    /**
     * @return array<string, int|string|null>
     */
    public function forCashOut(BankTransaction $uncleared): array
    {
        return [
            'cash_out_request_id' => $uncleared->cash_out_request_id,
            'status' => 'posted',
            'member_id' => $uncleared->member_id,
        ];
    }

    /**
     * @return array<string, int|string|null>
     */
    public function forExpenseDisbursement(BankTransaction $uncleared): array
    {
        return [
            'expense_disbursement_id' => $uncleared->expense_disbursement_id,
            'status' => 'posted',
            'member_id' => null,
        ];
    }

    /**
     * @return array<string, int|string|null>
     */
    public function forFeeDisbursement(BankTransaction $uncleared): array
    {
        return [
            'fee_disbursement_id' => $uncleared->fee_disbursement_id,
            'status' => 'posted',
            'member_id' => null,
        ];
    }

    /**
     * @return array<string, int|string|null>
     */
    public function forInvestDisbursement(BankTransaction $uncleared): array
    {
        return [
            'invest_disbursement_id' => $uncleared->invest_disbursement_id,
            'status' => 'posted',
            'member_id' => null,
        ];
    }

    /**
     * @return array<string, int|string|null>
     */
    public function forInvestReturn(BankTransaction $uncleared): array
    {
        return [
            'invest_return_id' => $uncleared->invest_return_id,
            'status' => 'posted',
            'member_id' => null,
        ];
    }
}
