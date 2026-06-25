<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\LegacyMigration\LegacyMigrationWorkingCopy;
use Illuminate\Support\Facades\Storage;

/**
 * Summarize uploaded legacy migration CSV files for the wizard UI.
 */
final class LegacyMigrationUploadDiagnostics
{
    /**
     * @return array{
     *     members: ?array{path: string, modified_at: string, row_count: int, headers: list<string>},
     *     loans: ?array{path: string, modified_at: string, row_count: int, headers: list<string>, has_loan_id: bool},
     *     payments: ?array{path: string, modified_at: string, row_count: int, headers: list<string>},
     * }
     */
    public function summarize(): array
    {
        return [
            'members' => $this->summarizeFile(
                LegacyMigrationWorkingCopy::MEMBERS_RELATIVE,
            ),
            'loans' => $this->summarizeLoansFile(),
            'payments' => $this->summarizeFile(
                LegacyMigrationWorkingCopy::PAYMENTS_RELATIVE,
            ),
        ];
    }

    /**
     * @return array{path: string, modified_at: string, row_count: int, headers: list<string>}|null
     */
    private function summarizeFile(string $relativePath): ?array
    {
        $absolutePath = $this->absolutePath($relativePath);

        if ($absolutePath === null) {
            return null;
        }

        return [
            'path' => $relativePath,
            'modified_at' => date('c', (int) filemtime($absolutePath)),
            'row_count' => count(AssociativeCsv::read($absolutePath)),
            'headers' => AssociativeCsv::headers($absolutePath),
        ];
    }

    /**
     * @return array{path: string, modified_at: string, row_count: int, headers: list<string>, has_loan_id: bool}|null
     */
    private function summarizeLoansFile(): ?array
    {
        $summary = $this->summarizeFile(LegacyMigrationWorkingCopy::LOANS_RELATIVE);

        if ($summary === null) {
            return null;
        }

        return [
            ...$summary,
            'has_loan_id' => in_array('loan_id', $summary['headers'], true),
        ];
    }

    private function absolutePath(string $relativePath): ?string
    {
        $disk = Storage::disk('local');

        if (! $disk->exists($relativePath)) {
            return null;
        }

        $absolutePath = $disk->path($relativePath);

        return is_readable($absolutePath) ? $absolutePath : null;
    }
}
