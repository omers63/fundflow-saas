<?php

declare(strict_types=1);

namespace App\Services\Members;

use App\Models\Tenant\Loan;
use App\Support\BusinessDay;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class GuarantorExposureExportService
{
    public function __construct(
        private MemberGuarantorExposureService $exposure,
    ) {}

    /**
     * @return list<string>
     */
    public static function csvHeaders(): array
    {
        return [
            'guarantor_member_number',
            'guarantor_name',
            'borrower_member_number',
            'borrower_name',
            'loan_id',
            'loan_status',
            'outstanding_amount',
            'exposure_risk',
            'overdue_installments',
            'liability_transferred',
        ];
    }

    public function downloadCsv(?\DateTimeInterface $from = null, ?\DateTimeInterface $until = null): StreamedResponse
    {
        $filename = 'guarantor-exposure-'.BusinessDay::now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($from, $until): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, self::csvHeaders());

            $this->portfolioQuery($from, $until)
                ->each(function (Loan $loan) use ($handle): void {
                    fputcsv($handle, $this->csvRow($loan));
                });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }

    public function portfolioQuery(?\DateTimeInterface $from = null, ?\DateTimeInterface $until = null): Builder
    {
        $query = Loan::query()
            ->whereNotNull('guarantor_member_id')
            ->whereNotIn('status', ['cancelled', 'rejected'])
            ->with(['member', 'guarantor'])
            ->withCount([
                'installments as overdue_installments_count' => fn (Builder $q): Builder => $q->where('status', 'overdue'),
            ])
            ->orderBy('guarantor_member_id')
            ->orderByDesc('id');

        if ($from !== null) {
            $query->whereDate('applied_at', '>=', $from);
        }

        if ($until !== null) {
            $query->whereDate('applied_at', '<=', $until);
        }

        return $query;
    }

    /**
     * @return list<int|float|string|null>
     */
    private function csvRow(Loan $loan): array
    {
        return [
            $loan->guarantor?->member_number,
            $loan->guarantor?->name,
            $loan->member?->member_number,
            $loan->member?->name,
            $loan->id,
            $loan->status,
            $loan->getOutstandingBalance(),
            $this->exposure->loanHasExposureRisk($loan) ? 'at_risk' : 'normal',
            $loan->overdue_installments_count ?? 0,
            $loan->guarantor_liability_transferred_at?->toDateTimeString(),
        ];
    }
}
