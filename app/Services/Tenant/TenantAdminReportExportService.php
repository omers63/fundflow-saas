<?php

declare(strict_types=1);

namespace App\Services\Tenant;

use App\Models\Tenant\Contribution;
use App\Models\Tenant\Loan;
use App\Models\Tenant\ReconciliationException;
use App\Models\Tenant\ReconciliationSnapshot;
use App\Services\Members\GuarantorExposureExportService;
use App\Services\ReconciliationPdfService;
use App\Support\BusinessDay;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class TenantAdminReportExportService
{
    public function __construct(
        private GuarantorExposureExportService $guarantorExposure,
        private FundAuditLogExportService $auditLogs,
        private ReconciliationPdfService $reconciliationPdf,
    ) {}

    public function download(
        string $type,
        string $format,
        ?string $from = null,
        ?string $until = null,
    ): StreamedResponse {
        $this->authorizeAdmin();

        $fromDate = filled($from) ? Carbon::parse($from)->startOfDay() : null;
        $untilDate = filled($until) ? Carbon::parse($until)->endOfDay() : null;

        if ($fromDate !== null && $untilDate !== null && $fromDate->gt($untilDate)) {
            throw new \InvalidArgumentException(__('The start date must be on or before the end date.'));
        }

        $normalizedFormat = strtolower($format);

        if ($normalizedFormat === 'xlsx') {
            $normalizedFormat = 'csv';
        }

        return match ($type) {
            'collections' => $this->exportCollections($normalizedFormat, $fromDate, $untilDate),
            'loans' => $this->exportLoans($normalizedFormat, $fromDate, $untilDate),
            'reconciliation' => $this->exportReconciliation($normalizedFormat, $fromDate, $untilDate),
            'audit' => $this->exportAudit($normalizedFormat, $fromDate, $untilDate),
            'guarantor_exposure' => $this->exportGuarantorExposure($normalizedFormat, $fromDate, $untilDate),
            default => throw new \InvalidArgumentException(__('Unknown report type: :type', ['type' => $type])),
        };
    }

    private function exportCollections(string $format, ?Carbon $from, ?Carbon $until): StreamedResponse
    {
        if ($format === 'pdf') {
            throw new \InvalidArgumentException(__('PDF export is not available for collections. Choose CSV or Excel.'));
        }

        $filename = 'collections-report-'.BusinessDay::now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($from, $until): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, [
                'member_number',
                'member_name',
                'period',
                'amount_due',
                'amount_collected',
                'status',
                'collection_status',
                'late_fee_amount',
                'posted_at',
                'paid_at',
            ]);

            $query = Contribution::query()->with('member')->orderByDesc('period')->orderByDesc('id');

            if ($from !== null) {
                $query->whereDate('period', '>=', $from->toDateString());
            }

            if ($until !== null) {
                $query->whereDate('period', '<=', $until->toDateString());
            }

            $query->each(function (Contribution $contribution) use ($handle): void {
                fputcsv($handle, [
                    $contribution->member?->member_number,
                    $contribution->member?->name,
                    $contribution->period?->format('Y-m-d'),
                    $contribution->amount_due,
                    $contribution->amount_collected,
                    $contribution->status,
                    $contribution->collection_status,
                    $contribution->late_fee_amount,
                    $contribution->posted_at?->toDateTimeString(),
                    $contribution->paid_at?->toDateTimeString(),
                ]);
            });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }

    private function exportLoans(string $format, ?Carbon $from, ?Carbon $until): StreamedResponse
    {
        if ($format === 'pdf') {
            throw new \InvalidArgumentException(__('PDF export is not available for loan portfolio reports. Choose CSV or Excel.'));
        }

        $filename = 'loan-portfolio-report-'.BusinessDay::now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($from, $until): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, [
                'loan_id',
                'member_number',
                'member_name',
                'guarantor_member_number',
                'guarantor_name',
                'status',
                'amount_approved',
                'amount_disbursed',
                'outstanding',
                'applied_at',
                'approved_at',
                'disbursed_at',
            ]);

            $query = Loan::query()->with(['member', 'guarantor'])->orderByDesc('id');

            if ($from !== null) {
                $query->whereDate('applied_at', '>=', $from->toDateString());
            }

            if ($until !== null) {
                $query->whereDate('applied_at', '<=', $until->toDateString());
            }

            $query->each(function (Loan $loan) use ($handle): void {
                fputcsv($handle, [
                    $loan->id,
                    $loan->member?->member_number,
                    $loan->member?->name,
                    $loan->guarantor?->member_number,
                    $loan->guarantor?->name,
                    $loan->status,
                    $loan->amount_approved,
                    $loan->amount_disbursed,
                    $loan->getOutstandingBalance(),
                    $loan->applied_at?->toDateString(),
                    $loan->approved_at?->toDateString(),
                    $loan->disbursed_at?->toDateString(),
                ]);
            });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }

    private function exportReconciliation(string $format, ?Carbon $from, ?Carbon $until): StreamedResponse
    {
        if ($format === 'pdf') {
            $snapshot = $this->latestSnapshotInRange($from, $until);

            if ($snapshot === null) {
                throw new \InvalidArgumentException(__('No reconciliation snapshot found in the selected date range.'));
            }

            return $this->reconciliationPdf->download($snapshot);
        }

        $filename = 'reconciliation-report-'.BusinessDay::now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($from, $until): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, [
                'record_kind',
                'id',
                'occurred_at',
                'status_or_mode',
                'severity_or_verdict',
                'domain_or_critical',
                'details',
            ]);

            $snapshots = ReconciliationSnapshot::query()->latest('as_of');
            $exceptions = ReconciliationException::query()->latest('raised_at');

            if ($from !== null) {
                $snapshots->where('as_of', '>=', $from);
                $exceptions->where('raised_at', '>=', $from);
            }

            if ($until !== null) {
                $snapshots->where('as_of', '<=', $until);
                $exceptions->where('raised_at', '<=', $until);
            }

            $snapshots->each(function (ReconciliationSnapshot $snapshot) use ($handle): void {
                fputcsv($handle, [
                    'snapshot',
                    $snapshot->id,
                    $snapshot->as_of?->toDateTimeString(),
                    $snapshot->mode,
                    $snapshot->is_passing ? 'pass' : 'fail',
                    $snapshot->critical_issues,
                    json_encode($snapshot->summary ?? [], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                ]);
            });

            $exceptions->each(function (ReconciliationException $exception) use ($handle): void {
                fputcsv($handle, [
                    'exception',
                    $exception->id,
                    $exception->raised_at?->toDateTimeString(),
                    $exception->status,
                    $exception->severity,
                    $exception->domain,
                    $exception->exception_code,
                ]);
            });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }

    private function exportAudit(string $format, ?Carbon $from, ?Carbon $until): StreamedResponse
    {
        if ($format === 'pdf') {
            throw new \InvalidArgumentException(__('PDF export is not available for audit trail exports. Choose CSV or Excel.'));
        }

        return $this->auditLogs->downloadCsv($from, $until);
    }

    private function exportGuarantorExposure(string $format, ?Carbon $from, ?Carbon $until): StreamedResponse
    {
        if ($format === 'pdf') {
            throw new \InvalidArgumentException(__('PDF export is not available for guarantor exposure. Choose CSV or Excel.'));
        }

        return $this->guarantorExposure->downloadCsv($from, $until);
    }

    private function latestSnapshotInRange(?Carbon $from, ?Carbon $until): ?ReconciliationSnapshot
    {
        $query = ReconciliationSnapshot::query()->latest('as_of');

        if ($from !== null) {
            $query->where('as_of', '>=', $from);
        }

        if ($until !== null) {
            $query->where('as_of', '<=', $until);
        }

        return $query->first();
    }

    private function authorizeAdmin(): void
    {
        if (! Auth::guard('tenant')->user()?->is_admin) {
            abort(403);
        }
    }
}
