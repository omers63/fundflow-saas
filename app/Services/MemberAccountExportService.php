<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Account;
use App\Support\BusinessDay;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class MemberAccountExportService
{
    /**
     * @return list<string>
     */
    public static function csvHeaders(): array
    {
        return [
            'member_number',
            'member_name',
            'account_type',
            'account_name',
            'balance',
        ];
    }

    public function downloadCsv(?string $accountType = null): StreamedResponse
    {
        $suffix = match ($accountType) {
            'cash' => 'cash-accounts',
            'fund' => 'fund-accounts',
            default => 'accounts',
        };

        $filename = 'member-'.$suffix.'-'.BusinessDay::now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($accountType): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, self::csvHeaders());

            $this->query($accountType)
                ->orderBy('member_id')
                ->orderBy('type')
                ->each(function (Account $account) use ($handle): void {
                    fputcsv($handle, $this->csvRow($account));
                });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }

    /**
     * @return Builder<Account>
     */
    private function query(?string $accountType): Builder
    {
        $query = Account::query()
            ->where('is_master', false)
            ->with('member');

        if ($accountType !== null) {
            $query->where('type', $accountType);
        }

        return $query;
    }

    /**
     * @return list<int|float|string|null>
     */
    private function csvRow(Account $account): array
    {
        return [
            $account->member?->member_number,
            $account->member?->name,
            $account->type,
            $account->name,
            $account->balance,
        ];
    }
}
