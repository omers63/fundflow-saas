<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Member;
use App\Support\BusinessDay;
use App\Support\Utf8CsvStream;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class MemberExportService
{
    /**
     * @return list<string>
     */
    public static function csvHeaders(): array
    {
        return [
            'member_number',
            'name',
            'email',
            'phone',
            'monthly_contribution_amount',
            'joined_at',
            'status',
            'parent_member_number',
            'parent_member_email',
            'contribution_arrears_cutoff_date',
            'opening_cash_balance',
            'opening_fund_balance',
            'cash_balance',
            'fund_balance',
        ];
    }

    public function downloadCsv(): StreamedResponse
    {
        $filename = 'members-'.BusinessDay::now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function (): void {
            $handle = Utf8CsvStream::open();
            fputcsv($handle, self::csvHeaders());

            Member::query()
                ->with(['parent', 'cashAccount', 'fundAccount'])
                ->orderBy('name')
                ->each(function (Member $member) use ($handle): void {
                    fputcsv($handle, $this->csvRow($member));
                });

            fclose($handle);
        }, $filename, [
            ...Utf8CsvStream::downloadHeaders(),
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }

    /**
     * @return list<int|float|string|null>
     */
    private function csvRow(Member $member): array
    {
        return [
            $member->member_number,
            $member->name,
            $member->email,
            $member->phone,
            $member->monthly_contribution_amount,
            $member->joined_at?->toDateString(),
            $member->status,
            $member->parent?->member_number,
            $member->parent?->email,
            $member->contribution_arrears_cutoff_date?->toDateString(),
            $member->opening_cash_balance,
            $member->opening_fund_balance,
            $member->cashAccount?->balance,
            $member->fundAccount?->balance,
        ];
    }
}
