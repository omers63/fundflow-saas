<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Member;
use App\Models\Tenant\MembershipApplication;
use App\Models\Tenant\User;
use App\Services\Tenant\HouseholdMemberService;
use App\Services\Tenant\MemberMembershipProfileService;
use App\Support\BusinessDay;
use App\Support\ContributionAmountSettings;
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
    /**
     * First CSV row key per contact email (file order). Used when parent_member_number is empty.
     *
     * @var array<string, string>
     */
    private array $emailFirstRowKeys = [];

    public function __construct(
        private readonly HouseholdMemberService $householdMembers,
        private readonly MemberOpeningBalanceService $openingBalances,
        private readonly ContributionCollectionCycleService $contributions,
        private readonly LegacyMemberIdentifierResolver $memberResolver,
        private readonly MemberMembershipProfileService $membershipProfiles,
        private readonly MembershipSubscriptionFeeService $subscriptionFees,
    ) {}

    /**
     * Import members from a UTF-8 CSV file with a header row.
     *
     * Required: name and (email or member_number)
     * Optional: member_number when email is provided, phone, monthly_contribution_amount, joined_at, status, password,
     * parent_member_number, parent_member_email, portal_pin, contribution_arrears_cutoff_date,
     * cutoff_cash_balance, cutoff_fund_balance,
     * and membership profile fields (gender, marital_status, national_id, date_of_birth, city, address,
     * mobile_phone, home_phone, work_phone, work_place, residency_place, occupation, employer, monthly_income,
     * bank_account_number, iban, next_of_kin_name, next_of_kin_phone, membership_fee_amount /
     * application_fee_amount, membership_fee_transfer_date / application_fee_transfer_date,
     * membership_fee_transfer_reference / application_fee_transfer_reference, message / applicant_message).
     * Declared application / membership fees are posted like approval (member + master cash deposit mirror,
     * then fee to master fees; no bank statement lines).
     *
     * Household links:
     * - When parent_member_number (or name/email parent columns) is set, that reference is used.
     * - When parent_member_number is empty, the first row with a given contact email is the household
     *   parent; later rows that reuse the same email become dependents of that first row.
     * - Re-import of existing members re-applies household links without re-creating the rows.
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

        $this->emailFirstRowKeys = $this->buildEmailFirstRowKeys($rows);

        try {
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
        } finally {
            $this->emailFirstRowKeys = [];
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

        $parentMember = $this->resolveParentMember($row);

        if ($this->rowNeedsResolvedParent($row) && $parentMember === null && ! $this->parentReferenceMatchesRowName($row)) {
            throw new InvalidArgumentException($this->missingParentReferenceMessage($row));
        }

        $existing = $this->findExistingMember($name, $memberNumber, $explicitEmail);

        if ($existing !== null) {
            $this->syncHouseholdFromImport($existing, $parentMember);

            return 'skipped';
        }

        $password = $this->cell($row, 'password');
        $plainPassword = strlen($password) >= 8 ? $password : $defaultPassword;

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
        $profileAttributes = $this->parseProfileAttributes($row);

        if ($phone === null || $phone === '') {
            $mobile = $profileAttributes['mobile_phone'] ?? null;
            if (is_string($mobile) && $mobile !== '') {
                $phone = $mobile;
            }
        }

        return DB::transaction(function () use ($name, $email, $phone, $plainPassword, $parentMember, $monthlyContribution, $joinedAt, $status, $memberNumber, $portalPin, $arrearsCutoffDate, $cashBalance, $fundBalance, $profileAttributes): string {
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

            $this->membershipProfiles->syncFromImportAttributes($member->fresh() ?? $member, $profileAttributes);

            $member = $member->fresh() ?? $member;
            $profile = $this->membershipProfiles->findForMember($member);

            if (
                $profile !== null
                && (float) ($profile->membership_fee_amount ?? 0) > 0.00001
            ) {
                $this->subscriptionFees->postOnLegacyMemberImport($profile, $member);
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
     * Household dependents often reuse the head's contact email and must not be skipped as "already imported"
     * solely because their email matches the head — they are keyed by member_number when provided.
     */
    private function findExistingMember(string $name, string $memberNumber, string $explicitEmail): ?Member
    {
        if ($memberNumber !== '') {
            return Member::query()->where('member_number', $memberNumber)->first();
        }

        if ($explicitEmail !== '') {
            $byMemberEmail = Member::query()->where('email', $explicitEmail)->first();

            if ($byMemberEmail !== null) {
                return $byMemberEmail;
            }

            $user = User::query()->where('email', $explicitEmail)->first();

            if ($user !== null) {
                return Member::query()->where('user_id', $user->id)->first();
            }
        }

        return Member::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->first();
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @return array<string, string>
     */
    private function buildEmailFirstRowKeys(array $rows): array
    {
        $firstKeys = [];

        foreach ($rows as $row) {
            $email = strtolower(trim($this->cell($row, 'email')));

            if ($email === '' || isset($firstKeys[$email])) {
                continue;
            }

            $firstKeys[$email] = $this->rowIdentityKey($row);
        }

        return $firstKeys;
    }

    /**
     * Stable key for a CSV row (used to match email-first household heads).
     *
     * @param  array<string, string>  $row
     */
    private function rowIdentityKey(array $row): string
    {
        $memberNumber = $this->cell($row, 'member_number');

        if ($memberNumber !== '') {
            return 'n:'.$memberNumber;
        }

        $email = strtolower(trim($this->cell($row, 'email')));
        $name = mb_strtolower(trim($this->cell($row, 'name')));

        if ($email !== '') {
            return 'e:'.$email.'|'.$name;
        }

        return 'name:'.$name;
    }

    /**
     * @param  array<string, string>  $row
     */
    private function canImportRow(array $row): bool
    {
        if (! $this->rowNeedsResolvedParent($row)) {
            return true;
        }

        if ($this->resolveParentMember($row) !== null) {
            return true;
        }

        return $this->parentReferenceMatchesRowName($row);
    }

    /**
     * Row cannot be imported as a household head because CSV / email rules assign a parent.
     *
     * @param  array<string, string>  $row
     */
    private function rowNeedsResolvedParent(array $row): bool
    {
        if ($this->rowHasExplicitParentReference($row)) {
            return ! $this->parentReferenceMatchesRowName($row);
        }

        return $this->emailInferredParentRowKey($row) !== null;
    }

    /**
     * Explicit parent columns on the row (not email-order inference).
     *
     * @param  array<string, string>  $row
     */
    private function rowHasExplicitParentReference(array $row): bool
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

        $inferredKey = $this->emailInferredParentRowKey($row);

        if ($inferredKey !== null) {
            return (string) __('Household parent for shared email was not found.');
        }

        return (string) __('Parent member was not found.');
    }

    /**
     * When parent_member_number is empty, later rows that reuse an earlier row's contact email
     * become dependents of that first email encounter.
     *
     * @param  array<string, string>  $row
     */
    private function emailInferredParentRowKey(array $row): ?string
    {
        if ($this->cell($row, 'parent_member_number') !== '') {
            return null;
        }

        $email = strtolower(trim($this->cell($row, 'email')));

        if ($email === '') {
            return null;
        }

        $firstKey = $this->emailFirstRowKeys[$email] ?? null;

        if ($firstKey === null || $firstKey === $this->rowIdentityKey($row)) {
            return null;
        }

        return $firstKey;
    }

    private function findMemberByRowIdentityKey(string $key): ?Member
    {
        if (str_starts_with($key, 'n:')) {
            return $this->memberResolver->findByMemberNumber(substr($key, 2));
        }

        if (str_starts_with($key, 'e:')) {
            $payload = substr($key, 2);
            $parts = explode('|', $payload, 2);
            $email = $parts[0] ?? '';
            $name = $parts[1] ?? '';

            if ($email !== '') {
                $byEmail = $this->memberResolver->findByEmail($email);

                if ($byEmail !== null) {
                    return $byEmail;
                }
            }

            if ($name !== '') {
                return Member::query()
                    ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                    ->whereNull('parent_member_id')
                    ->first()
                    ?? Member::query()
                        ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                        ->first();
            }

            return null;
        }

        if (str_starts_with($key, 'name:')) {
            return $this->memberResolver->findByName(substr($key, 5));
        }

        return null;
    }

    /**
     * @param  array<string, string>  $row
     */
    private function resolveParentMember(array $row): ?Member
    {
        $parentNumber = $this->cell($row, 'parent_member_number');

        if ($parentNumber !== '') {
            if ($this->parentReferenceMatchesRowName($row)) {
                return null;
            }

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

        $inferredKey = $this->emailInferredParentRowKey($row);

        if ($inferredKey === null) {
            return null;
        }

        return $this->findMemberByRowIdentityKey($inferredKey);
    }

    /**
     * Re-apply household parent/dependent link for an existing member (fresh re-import).
     */
    private function syncHouseholdFromImport(Member $member, ?Member $parentMember): void
    {
        if ($parentMember !== null) {
            if ((int) $member->id === (int) $parentMember->id) {
                return;
            }

            if ((int) ($member->parent_member_id ?? 0) === (int) $parentMember->id) {
                return;
            }

            $member->loadMissing('user');

            if ($member->user !== null) {
                $this->householdMembers->assignToHousehold($member, $parentMember);

                return;
            }

            $householdEmail = $this->resolveParentHouseholdEmail($parentMember);
            $member->update([
                'parent_member_id' => $parentMember->id,
                'email' => $householdEmail,
                'household_email' => $householdEmail,
                'is_separated' => false,
                'direct_login_enabled' => false,
            ]);

            return;
        }

        if ($member->parent_member_id === null) {
            return;
        }

        $member->loadMissing('user');

        if ($member->user !== null) {
            $this->householdMembers->removeFromHousehold($member);

            return;
        }

        $member->update([
            'parent_member_id' => null,
        ]);
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

        if (! ContributionAmountSettings::isValidAmount((int) $amount)) {
            throw new InvalidArgumentException(
                __('monthly_contribution_amount must be one of: :amounts.', [
                    'amounts' => implode(', ', array_map('strval', ContributionAmountSettings::steps())),
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
        $headers = array_map(fn ($header) => $this->normalizeCsvHeaderKey((string) $header), $headers);

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

    private function normalizeCsvHeaderKey(string $header): string
    {
        $h = trim($header);
        if (str_starts_with($h, "\xEF\xBB\xBF")) {
            $h = substr($h, 3);
        }

        $h = strtolower(str_replace(["\xc2\xa0", ' ', '-'], ['_', '_', '_'], $h));
        $h = preg_replace('/_+/', '_', $h) ?? $h;
        $h = trim($h, '_');

        return match ($h) {
            'mobile', 'cell', 'mobile_number', 'cell_phone', 'whatsapp' => 'mobile_phone',
            'national_id_number', 'nid', 'iqama', 'iqama_number' => 'national_id',
            'dob', 'birth_date', 'birthdate' => 'date_of_birth',
            'bank_account', 'account_number', 'bank_acc' => 'bank_account_number',
            'kin_name', 'emergency_contact_name', 'nok_name' => 'next_of_kin_name',
            'kin_phone', 'emergency_contact_phone', 'nok_phone' => 'next_of_kin_phone',
            'application_fee_amount', 'app_fee_amount', 'subscription_fee_amount' => 'membership_fee_amount',
            'application_fee_transfer_date', 'app_fee_transfer_date', 'fee_transfer_date' => 'membership_fee_transfer_date',
            'application_fee_transfer_reference', 'app_fee_transfer_reference', 'fee_transfer_reference',
            'membership_fee_reference' => 'membership_fee_transfer_reference',
            'applicant_message', 'applicant_msg', 'application_message' => 'message',
            'cut_off_cash_balance', 'opening_cash_balance' => 'cutoff_cash_balance',
            'cut_off_fund_balance', 'opening_fund_balance' => 'cutoff_fund_balance',
            default => $h,
        };
    }

    /**
     * @param  array<string, string>  $row
     * @return array<string, mixed>
     */
    private function parseProfileAttributes(array $row): array
    {
        $attrs = [];

        foreach ([
            'city',
            'address',
            'mobile_phone',
            'home_phone',
            'work_phone',
            'work_place',
            'residency_place',
            'occupation',
            'employer',
            'bank_account_number',
            'next_of_kin_name',
            'next_of_kin_phone',
            'message',
            'membership_fee_transfer_reference',
        ] as $key) {
            $value = $this->cell($row, $key);
            if ($value !== '') {
                $attrs[$key] = $value;
            }
        }

        $gender = $this->normalizeGender($this->cell($row, 'gender'));
        if ($gender !== null) {
            $attrs['gender'] = $gender;
        }

        $marital = $this->normalizeMaritalStatus($this->cell($row, 'marital_status'));
        if ($marital !== null) {
            $attrs['marital_status'] = $marital;
        }

        $nationalId = $this->cell($row, 'national_id');
        if ($nationalId !== '') {
            $attrs['national_id'] = $nationalId;
        }

        $dob = $this->cell($row, 'date_of_birth');
        if ($dob !== '') {
            $attrs['date_of_birth'] = $this->parseFlexibleDateToDateString($dob, 'date_of_birth');
        }

        $income = $this->cell($row, 'monthly_income');
        if ($income !== '') {
            if (! is_numeric($income) || (float) $income < 0) {
                throw new InvalidArgumentException(__('monthly_income must be a non-negative number.'));
            }
            $attrs['monthly_income'] = round((float) $income, 2);
        }

        $iban = $this->cell($row, 'iban');
        if ($iban !== '') {
            $attrs['iban'] = strtoupper(preg_replace('/\s+/', '', $iban) ?? $iban);
        }

        $feeAmount = $this->cell($row, 'membership_fee_amount');
        if ($feeAmount !== '') {
            if (! is_numeric($feeAmount) || (float) $feeAmount < 0) {
                throw new InvalidArgumentException(__('membership_fee_amount must be a non-negative number.'));
            }
            $attrs['membership_fee_amount'] = round((float) $feeAmount, 2);
        }

        $feeDate = $this->cell($row, 'membership_fee_transfer_date');
        if ($feeDate !== '') {
            $attrs['membership_fee_transfer_date'] = $this->parseFlexibleDateToDateString($feeDate, 'membership_fee_transfer_date');
        }

        return $attrs;
    }

    private function normalizeGender(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        $t = trim($value);
        $map = [
            'ذكر' => 'male',
            'أنثى' => 'female',
            'انثى' => 'female',
            'أنثي' => 'female',
            'انثي' => 'female',
            'أخرى' => 'other',
            'أخر' => 'other',
            'آخر' => 'other',
        ];
        if (isset($map[$t])) {
            return $map[$t];
        }

        $v = strtolower($t);
        $allowed = array_keys(MembershipApplication::genderOptions());
        if (in_array($v, $allowed, true)) {
            return $v;
        }

        throw new InvalidArgumentException(
            __('gender must be one of: :values (got: :value).', [
                'values' => implode(', ', $allowed),
                'value' => $value,
            ])
        );
    }

    private function normalizeMaritalStatus(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        $t = trim($value);
        $map = [
            'أعزب' => 'single',
            'عزباء' => 'single',
            'متزوج' => 'married',
            'متزوجة' => 'married',
            'مطلق' => 'divorced',
            'مطلقة' => 'divorced',
            'أرمل' => 'widowed',
            'أرملة' => 'widowed',
        ];
        if (isset($map[$t])) {
            return $map[$t];
        }

        $v = strtolower($t);
        $allowed = array_keys(MembershipApplication::maritalStatusOptions());
        if (in_array($v, $allowed, true)) {
            return $v;
        }

        throw new InvalidArgumentException(
            __('marital_status must be one of: :values (got: :value).', [
                'values' => implode(', ', $allowed),
                'value' => $value,
            ])
        );
    }

    private function parseFlexibleDateToDateString(string $value, string $fieldLabel): string
    {
        $v = trim($value);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            try {
                return Carbon::createFromFormat('!Y-m-d', $v)->toDateString();
            } catch (Throwable) {
                throw new InvalidArgumentException(__('Invalid :field: :value', ['field' => $fieldLabel, 'value' => $value]));
            }
        }

        foreach (['d/m/Y', 'd/m/y', 'd-m-Y', 'm/d/Y', 'm/d/y', 'Y/m/d'] as $format) {
            try {
                return Carbon::createFromFormat($format, $v)->toDateString();
            } catch (Throwable) {
                continue;
            }
        }

        try {
            return Carbon::parse($v)->toDateString();
        } catch (Throwable) {
            throw new InvalidArgumentException(__('Invalid :field: :value', ['field' => $fieldLabel, 'value' => $value]));
        }
    }

    /**
     * @param  array<string, string>  $row
     */
    private function cell(array $row, string $key): string
    {
        return trim((string) ($row[$key] ?? ''));
    }
}
