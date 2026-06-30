<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant\Account;
use App\Models\Tenant\InvestDisbursement;
use App\Models\Tenant\InvestReturn;
use App\Models\Tenant\Transaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Lang;

/**
 * Round-trip rules for master invest ledger CSV export and import.
 *
 * Each economic event posts two lines on master invest (e.g. return credit + fund transfer debit).
 * Export and import only surface the importable leg: credits = invest return, debits = invest out.
 */
final class MasterInvestLedgerImport
{
    private const RESERVE_FUNDING_KEY = ':description (reserve funding)';

    private const RESERVE_RETURN_KEY = ':description (reserve return)';

    private const INVESTMENT_RETURN_KEY = ':description (investment return)';

    private const INVEST_OUT_KEY = '(invest out)';

    public static function isInvestAccount(Account $account): bool
    {
        return $account->is_master && $account->type === 'invest';
    }

    /**
     * @param  Builder<Transaction>  $query
     * @return Builder<Transaction>
     */
    public static function applyExportableScope(Builder $query): Builder
    {
        return $query->where(function (Builder $outer): void {
            $outer
                ->where(function (Builder $returns): void {
                    $returns->where('type', 'credit')
                        ->where('reference_type', InvestReturn::class);
                })
                ->orWhere(function (Builder $disbursements): void {
                    $disbursements->where('type', 'debit')
                        ->where('reference_type', InvestDisbursement::class);
                });
        });
    }

    /**
     * @param  array<string, string>  $row
     */
    public static function shouldSkipImportRow(Account $account, array $row): bool
    {
        if (! self::isInvestAccount($account)) {
            return false;
        }

        $transactionId = trim(self::cell($row, 'id'));

        if ($transactionId !== '' && is_numeric($transactionId)) {
            $exists = Transaction::query()
                ->where('account_id', $account->id)
                ->whereKey((int) $transactionId)
                ->exists();

            if ($exists) {
                return true;
            }
        }

        if (self::isInternalInvestLegRow($row)) {
            return true;
        }

        $type = strtolower(self::cell($row, 'type'));
        $referenceType = self::resolveReferenceType($row);

        if ($referenceType === InvestReturn::class && $type === 'debit') {
            return true;
        }

        if ($referenceType === InvestDisbursement::class && $type === 'credit') {
            return true;
        }

        if (self::businessReferenceAlreadyExists($account, $row)) {
            return true;
        }

        return false;
    }

    /**
     * @param  array<string, string>  $row
     */
    public static function isImportableInvestEventRow(array $row): bool
    {
        if (self::isInternalInvestLegRow($row)) {
            return false;
        }

        $type = strtolower(self::cell($row, 'type'));
        $referenceType = self::resolveReferenceType($row);

        if ($referenceType === InvestReturn::class) {
            return $type === 'credit';
        }

        if ($referenceType === InvestDisbursement::class) {
            return $type === 'debit';
        }

        if ($type === 'credit' && self::descriptionContainsLocalizedSuffix($row, self::INVESTMENT_RETURN_KEY)) {
            return true;
        }

        if ($type === 'debit' && self::descriptionContainsLocalizedSuffix($row, self::INVEST_OUT_KEY)) {
            return true;
        }

        return in_array($type, ['credit', 'debit', 'in', 'out'], true);
    }

    public static function sanitizeInvestImportDescription(string $description): string
    {
        $description = trim($description);

        if ($description === '') {
            return '';
        }

        $description = preg_replace(
            '/^(?:Invest (?:return|disbursement)|عائد استثمار|صرف استثمار)\s*#\d+\s*[–\-]\s*/u',
            '',
            $description,
        ) ?? $description;

        foreach ([
            self::INVESTMENT_RETURN_KEY,
            self::INVEST_OUT_KEY,
            self::RESERVE_FUNDING_KEY,
            self::RESERVE_RETURN_KEY,
        ] as $translationKey) {
            foreach (self::localizedSuffixes($translationKey) as $suffix) {
                $description = self::stripSuffix($description, $suffix);
            }
        }

        return trim($description);
    }

    /**
     * @param  array<string, string>  $row
     */
    private static function businessReferenceAlreadyExists(Account $account, array $row): bool
    {
        $referenceId = self::cell($row, 'reference_id');

        if ($referenceId === '' || ! is_numeric($referenceId)) {
            return false;
        }

        $type = strtolower(self::cell($row, 'type'));
        $referenceType = self::resolveReferenceType($row);

        if ($referenceType === null) {
            return false;
        }

        if ($referenceType === InvestReturn::class && $type !== 'credit') {
            return false;
        }

        if ($referenceType === InvestDisbursement::class && $type !== 'debit') {
            return false;
        }

        return Transaction::query()
            ->where('account_id', $account->id)
            ->where('reference_type', $referenceType)
            ->where('reference_id', (int) $referenceId)
            ->exists();
    }

    /**
     * @param  array<string, string>  $row
     */
    private static function resolveReferenceType(array $row): ?string
    {
        $raw = self::cell($row, 'reference_type');

        if ($raw === '') {
            return null;
        }

        if (str_contains($raw, 'InvestReturn')) {
            return InvestReturn::class;
        }

        if (str_contains($raw, 'InvestDisbursement')) {
            return InvestDisbursement::class;
        }

        return $raw;
    }

    /**
     * @param  array<string, string>  $row
     */
    private static function isInternalInvestLegRow(array $row): bool
    {
        $description = self::cell($row, 'description');
        $type = strtolower(self::cell($row, 'type'));
        $referenceType = self::resolveReferenceType($row);

        if ($description === '') {
            return false;
        }

        if ($referenceType === InvestReturn::class && $type === 'debit') {
            return true;
        }

        if ($referenceType === InvestDisbursement::class && $type === 'credit') {
            return true;
        }

        if ($type === 'credit' && self::descriptionContainsLocalizedSuffix($description, self::RESERVE_FUNDING_KEY)) {
            return true;
        }

        if ($type === 'debit' && self::descriptionContainsLocalizedSuffix($description, self::RESERVE_RETURN_KEY)) {
            return true;
        }

        return false;
    }

    /**
     * @param  array<string, string>|string  $rowOrDescription
     */
    private static function descriptionContainsLocalizedSuffix(array|string $rowOrDescription, string $translationKey): bool
    {
        $description = is_array($rowOrDescription)
            ? self::cell($rowOrDescription, 'description')
            : $rowOrDescription;

        if ($description === '') {
            return false;
        }

        foreach (self::localizedSuffixes($translationKey) as $suffix) {
            if (str_contains($description, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function localizedSuffixes(string $translationKey): array
    {
        $suffixes = [];

        foreach (['en', 'ar', config('app.locale'), config('app.fallback_locale')] as $locale) {
            if (! is_string($locale) || $locale === '') {
                continue;
            }

            if (str_starts_with($translationKey, ':description')) {
                $suffix = trim(str_replace(':description', '', Lang::get($translationKey, ['description' => ''], $locale)));
            } else {
                $suffix = trim(Lang::get($translationKey, [], $locale));
            }

            if ($suffix !== '') {
                $suffixes[] = $suffix;
            }
        }

        return array_values(array_unique($suffixes));
    }

    private static function stripSuffix(string $description, string $suffix): string
    {
        if ($suffix === '') {
            return $description;
        }

        if (str_ends_with($description, $suffix)) {
            return trim(substr($description, 0, -strlen($suffix)));
        }

        return trim(str_replace($suffix, '', $description));
    }

    /**
     * @param  array<string, string>  $row
     */
    private static function cell(array $row, string $key): string
    {
        $normalized = [];

        foreach ($row as $header => $value) {
            $normalized[strtolower(trim((string) $header))] = trim((string) $value);
        }

        return $normalized[strtolower($key)] ?? '';
    }
}
