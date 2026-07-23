<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Services\Tenant\HouseholdMemberService;
use App\Support\BusinessDay;
use App\Support\LegacyMemberIdentifierResolver;
use App\Support\LegacyMemberStatusMapper;
use App\Support\MemberUserEmail;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

final class MemberImportService
{
    public function __construct(
        private readonly HouseholdMemberService $householdMembers,
        private readonly MemberOpeningBalanceService $openingBalances,
        private readonly ContributionCollectionCycleService $contributions,
        private readonly LegacyMemberIdentifierResolver $memberResolver,
    ) {}

    /**
     * Import members from a UTF-8 CSV file with a header row.
     *
     * Required: name and (email or member_number)
     * Optional: member_number when email is provided, phone, monthly_contribution_amount, joined_at, status, password,
     * parent_member_number, parent_member_email, portal_pin, contribution_arrears_cutoff_date,
     * cutoff_cash_balance, cutoff_fund_balance
     *
     * Parent rows may appear after dependent rows; the importer resolves household links in multiple passes.
     *
     * @return array{created: int, skipped: int, failed: int, errors: array<int, string>}
     */
    public function import(string $absolutePath, string $defaultPassword, ?string $defaultArrearsCutoffDate = null): array
    {
        $this->authorizeImport();

        if (strlen($defaultPassword) < 8) {
            return [
                'created' => 0,
                'skipped' => 0,
                'failed' => 0,
                'errors' => [__('Default password must be at least 8 characters.')],
            ];
        }

        $created = 0;
        $skipped = 0;
        $failed = 0;
        $errors = [];

        $rows = $this->parseAssociativeCsv($absolutePath);

        if ($rows === []) {
            return [
                'created' => 0,
                'skipped' => 0,
                'failed' => 0,
                'errors' => [__('The file is empty or has no data rows after the header.')],
            ];
        }

        $defaultArrearsCutoffDate = $this->normalizeOptionalDate($defaultArrearsCutoffDate);
        $lineBase = 2;

        $pending = [];

        foreach ($rows as $index => $row) {
            $pending[] = [
                'line' => $lineBase + $index,
                'row' => $row,
            ];
        }

        while ($pending !== []) {
            $deferred = [];
            $progress = false;

            foreach ($pending as $item) {
                if (! $this->canImportRow($item['row'])) {
                    $deferred[] = $item;

                    continue;
                }

                try {
                    $result = $this->importRow($item['row'], $defaultPassword, $defaultArrearsCutoffDate);

                    if ($result === 'skipped') {
                        $skipped++;
                    } else {
                        $created++;
                    }

                    $progress = true;
                } catch (Throwable $e) {
                    $failed++;
                    $errors[] = "Row {$item['line']}: {$e->getMessage()}";
                    $progress = true;
                }
            }

            if (! $progress) {
                foreach ($deferred as $item) {
                    $failed++;
                    $errors[] = "Row {$item['line']}: {$this->missingParentReferenceMessage($item['row'])}";
                }

                break;
            }

            $pending = $deferred;
        }

        return [
            'created' => $created,
            'skipped' => $skipped,
            'failed' => $failed,
            'errors' => $errors,
        ];
    }

    /**
     * @param  array<string, string>  $row
     */
    private function importRow(array $row, string $defaultPassword, ?string $defaultArrearsCutoffDate): string
    {
        $name = trim($this->cell($row, 'name'));

        if ($name === '') {
            throw new InvalidArgumentException(__('name is required.'));
        }

        $memberNumber = $this->cell($row, 'member_number');
        $explicitEmail = strtolower(trim($this->cell($row, 'email')));

        if ($this->rowAlreadyImported($name, $memberNumber, $explicitEmail)) {
            return 'skipped';
        }

        $password = $this->cell($row, 'password');
        $plainPassword = strlen($password) >= 8 ? $password : $defaultPassword;

        $parentMember = $this->resolveParentMember($row);

        if ($this->rowHasParentReference($row) && $parentMember === null && ! $this->parentReferenceMatchesRowName($row)) {
            throw new InvalidArgumentException($this->missingParentReferenceMessage($row));
        }

        $email = $this->resolveImportEmail($row, $name, $memberNumber, $parentMember);
        $monthlyContribution = $this->parseMonthlyContribution($row);
        $joinedAt = $this->parseJoinedAt($row);
        $status = $this->parseStatus($this->cell($row, 'status'));
        $portalPin = $this->cell($row, 'portal_pin') ?: null;
        $arrearsCutoffDate = $this->parseRowArrearsCutoffDate($row, $defaultArrearsCutoffDate);
        $cashBalance = $this->parseCutoffBalance($row, 'cutoff_cash_balance');
        $fundBalance = $this->parseCutoffBalance($row, 'cutoff_fund_balance');

        if (($cashBalance !== 0.0 || $fundBalance !== 0.0) && $arrearsCutoffDate === null) {
            throw new InvalidArgumentException(
                __('contribution_arrears_cutoff_date is required when posting cut-off cash or fund balances.')
            );
        }

        $phone = $this->cell($row, 'phone') ?: null;

        return DB::transaction(function () use ($name, $email, $phone, $plainPassword, $parentMember, $monthlyContribution, $joinedAt, $status, $memberNumber, $portalPin, $arrearsCutoffDate, $cashBalance, $fundBalance): string {
            $attributes = [
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'monthly_contribution_amount' => $monthlyContribution,
                'joined_at' => $joinedAt,
                'status' => $status,
                'portal_pin' => $portalPin,
            ];

            if ($memberNumber !== '') {
                $attributes['member_number'] = $memberNumber;
            }

            if ($parentMember !== null) {
                $attributes['parent_member_id'] = $parentMember->id;
            }

            $member = $this->householdMembers->createFromAdmin($attributes, $plainPassword, sendOnboardingGreeting: false);

            if ($arrearsCutoffDate !== null) {
                $cutoff = Carbon::parse($arrearsCutoffDate);

                $member->update([
                    'contribution_arrears_cutoff_date' => $cutoff->toDateString(),
                ]);

                $this->contributions->dismissPreCutoffPendingContributions($member->fresh() ?? $member);

                if (abs($cashBalance) > 0.00001 || abs($fundBalance) > 0.00001) {
                    $this->openingBalances->postOpeningBalances(
                        $member->fresh(),
                        $cashBalance,
                        $fundBalance,
                        $cutoff,
                        'IMPORT_CUTOFF',
                    );
                }
            }

            return 'created';
        });
    }

    /**
     * @throws AuthorizationException
     */
    private function authorizeImport(): void
    {
        $user = auth('tenant')->user();

        if ($user === null) {
            throw new AuthorizationException(__('You must be signed in to import members.'));
        }

        if ($user->is_admin) {
            return;
        }

        throw new AuthorizationException(__('You do not have permission to import members.'));
    }

    /**
     * When member_number is present it is the authoritative identity for legacy imports.
     * Household dependents often reuse the head's contact email and must not be skipped.
     */
    private function rowAlreadyImported(string $name, string $memberNumber, string $explicitEmail): bool
    {
        if ($memberNumber !== '') {
            return Member::query()->where('member_number', $memberNumber)->exists();
        }

        if ($explicitEmail !== '') {
            if (Member::query()->where('email', $explicitEmail)->exists()) {
                return true;
            }

            if (User::query()->where('email', $explicitEmail)->exists()) {
                return true;
            }
        }

        return Member::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->exists();
    }

    /**
     * @param  array<string, string>  $row
     */
    private function canImportRow(array $row): bool
    {
        if (! $this->rowHasParentReference($row)) {
            return true;
        }

        if ($this->resolveParentMember($row) !== null) {
            return true;
        }

        return $this->parentReferenceMatchesRowName($row);
    }

    /**
     * @param  array<string, string>  $row
     */
    private function rowHasParentReference(array $row): bool
    {
        if ($this->cell($row, 'parent_member_number') !== '') {
            return true;
        }

        foreach (['parent_member_name', 'parent_name', 'parent_member_email', 'parent_email'] as $key) {
            if ($this->cell($row, $key) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, string>  $row
     */
    private function missingParentReferenceMessage(array $row): string
    {
        $parentNumber = $this->cell($row, 'parent_member_number');

        if ($parentNumber !== '') {
            return (string) __('Parent member :identifier was not found.', ['identifier' => $parentNumber]);
        }

        foreach (['parent_member_email', 'parent_email'] as $key) {
            $parentEmail = strtolower(trim($this->cell($row, $key)));

            if ($parentEmail !== '') {
                return (string) __('Parent member email :email was not found.', ['email' => $parentEmail]);
            }
        }

        return (string) __('Parent member was not found.');
    }

    /**
     * @param  array<string, string>  $row
     */
    private function resolveParentMember(array $row): ?Member
    {
        $parentNumber = $this->cell($row, 'parent_member_number');

        if ($parentNumber !== '') {
            return $this->memberResolver->findByNumberOrLegacyLabel($parentNumber);
        }

        foreach (['parent_member_name', 'parent_name'] as $key) {
            $parentName = $this->cell($row, $key);

            if ($parentName !== '') {
                return $this->memberResolver->findByNumberOrLegacyLabel($parentName);
            }
        }

        foreach (['parent_member_email', 'parent_email'] as $key) {
            $parentEmail = strtolower(trim($this->cell($row, $key)));

            if ($parentEmail === '') {
                continue;
            }

            return $this->memberResolver->findByEmail($parentEmail);
        }

        return null;
    }

    /**
     * Legacy member exports sometimes put the household head's own shorthand label
     * in parent_member_number on the head row itself.
     *
     * @param  array<string, string>  $row
     */
    private function parentReferenceMatchesRowName(array $row): bool
    {
        $parentLabel = $this->cell($row, 'parent_member_number');

        if ($parentLabel === '') {
            foreach (['parent_member_name', 'parent_name'] as $key) {
                $parentLabel = $this->cell($row, $key);

                if ($parentLabel !== '') {
                    break;
                }
            }
        }

        $name = trim($this->cell($row, 'name'));

        if ($parentLabel === '' || $name === '') {
            return false;
        }

        $words = preg_split('/\s+/u', $parentLabel, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($words === []) {
            return false;
        }

        $first = mb_strtolower($words[0]);
        $last = mb_strtolower($words[array_key_last($words)]);
        $normalizedName = mb_strtolower($name);

        return str_starts_with($normalizedName, $first) && str_ends_with($normalizedName, $last);
    }

    private function resolveImportEmail(array $row, string $name, string $memberNumber, ?Member $parentMember): string
    {
        if ($parentMember !== null) {
            return $this->resolveParentHouseholdEmail($parentMember);
        }

        $explicitEmail = strtolower(trim($this->cell($row, 'email')));

        if ($explicitEmail !== '') {
            $validator = Validator::make(['email' => $explicitEmail], ['email' => 'required|email']);

            if ($validator->fails()) {
                throw new InvalidArgumentException(__('Invalid email address.'));
            }

            return $explicitEmail;
        }

        if ($memberNumber === '') {
            $nameMatches = Member::query()
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                ->count();

            if ($nameMatches > 1) {
                throw new InvalidArgumentException(__('Multiple members share this name — provide member_number.'));
            }
        }

        $token = $memberNumber !== ''
            ? (Str::slug($memberNumber, '.') ?: 'member')
            : (Str::slug($name, '.') ?: 'member');

        return app(MemberUserEmail::class)->resolveForNewMember(
            $this->syntheticImportEmailFromToken($token),
        );
    }

    private function resolveParentHouseholdEmail(Member $parentMember): string
    {
        $householdEmail = strtolower(trim((string) ($parentMember->household_email ?? $parentMember->email ?? '')));

        if ($householdEmail === '') {
            throw new InvalidArgumentException(__('Parent member must have a household email.'));
        }

        return $householdEmail;
    }

    private function syntheticImportEmailFromToken(string $token): string
    {
        return 'legacy.'.Str::lower($token).'@import.fundflow.local';
    }

    /**
     * @param  array<string, string>  $row
     */
    private function parseMonthlyContribution(array $row): float
    {
        $raw = $this->cell($row, 'monthly_contribution_amount');

        if ($raw === '') {
            return 500.0;
        }

        if (! is_numeric($raw)) {
            throw new InvalidArgumentException(__('monthly_contribution_amount must be numeric.'));
        }

        $amount = round((float) $raw, 2);

        if (! in_array((int) $amount, Member::CONTRIBUTION_STEPS, true)) {
            throw new InvalidArgumentException(
                __('monthly_contribution_amount must be one of: :amounts.', [
                    'amounts' => implode(', ', array_map('strval', Member::CONTRIBUTION_STEPS)),
                ])
            );
        }

        return $amount;
    }

    /**
     * @param  array<string, string>  $row
     */
    private function parseJoinedAt(array $row): Carbon
    {
        $raw = $this->cell($row, 'joined_at');

        if ($raw === '') {
            return BusinessDay::now();
        }

        try {
            return Carbon::parse($raw)->startOfDay();
        } catch (Throwable) {
            throw new InvalidArgumentException(__('joined_at must be a valid date.'));
        }
    }

    private function parseStatus(string $value): string
    {
        return LegacyMemberStatusMapper::normalize($value);
    }

    /**
     * @param  array<string, string>  $row
     */
    private function parseRowArrearsCutoffDate(array $row, ?string $defaultArrearsCutoffDate): ?string
    {
        foreach ([
            'contribution_arrears_cutoff_date',
            'arrears_cutoff_date',
            'import_arrears_cutoff_date',
            'migration_cutoff_date',
            'cut_off_date',
        ] as $key) {
            $value = $this->normalizeOptionalDate($this->cell($row, $key));

            if ($value !== null) {
                return $value;
            }
        }

        return $defaultArrearsCutoffDate;
    }

    /**
     * @param  array<string, string>  $row
     */
    private function parseCutoffBalance(array $row, string $primaryKey): float
    {
        $aliases = match ($primaryKey) {
            'cutoff_cash_balance' => [
                'cutoff_cash_balance',
                'cut_off_cash_balance',
                'opening_cash_balance',
                'import_cutoff_cash_balance',
            ],
            'cutoff_fund_balance' => [
                'cutoff_fund_balance',
                'cut_off_fund_balance',
                'opening_fund_balance',
                'import_cutoff_fund_balance',
            ],
            default => [$primaryKey],
        };

        foreach ($aliases as $key) {
            $raw = $this->cell($row, $key);

            if ($raw === '') {
                continue;
            }

            if (! is_numeric($raw)) {
                throw new InvalidArgumentException(__(':column must be numeric.', ['column' => $key]));
            }

            return round((float) $raw, 2);
        }

        return 0.0;
    }

    private function normalizeOptionalDate(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (Throwable) {
            throw new InvalidArgumentException(__('Cut-off date must be a valid date.'));
        }
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function parseAssociativeCsv(string $absolutePath): array
    {
        if (! is_readable($absolutePath)) {
            throw new InvalidArgumentException(__('Cannot read the uploaded file.'));
        }

        $content = file_get_contents($absolutePath);

        if ($content === false) {
            throw new InvalidArgumentException(__('Cannot read the uploaded file.'));
        }

        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;

        $lines = preg_split('/\r\n|\r|\n/', $content);
        $lines = array_values(array_filter($lines, fn ($line) => trim((string) $line) !== ''));

        if (count($lines) < 2) {
            return [];
        }

        $headerLine = array_shift($lines);
        $headers = str_getcsv((string) $headerLine, ',', '"', '\\');
        $headers = array_map(fn ($header) => strtolower(trim(str_replace(' ', '_', (string) $header))), $headers);

        $rows = [];

        foreach ($lines as $line) {
            $cells = str_getcsv((string) $line, ',', '"', '\\');
            $assoc = [];

            foreach ($headers as $index => $key) {
                if ($key === '') {
                    continue;
                }

                $assoc[$key] = isset($cells[$index]) ? trim((string) $cells[$index]) : '';
            }

            $rows[] = $assoc;
        }

        return $rows;
    }

    /**
     * @param  array<string, string>  $row
     */
    private function cell(array $row, string $key): string
    {
        return trim((string) ($row[$key] ?? ''));
    }
}
