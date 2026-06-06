<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Services\Tenant\HouseholdMemberService;
use App\Support\BusinessDay;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use Throwable;

final class MemberImportService
{
    public function __construct(
        private readonly HouseholdMemberService $householdMembers,
        private readonly MemberOpeningBalanceService $openingBalances,
        private readonly ContributionCollectionCycleService $contributions,
    ) {}

    /**
     * Import members from a UTF-8 CSV file with a header row.
     *
     * Required: name, email
     * Optional: member_number, phone, monthly_contribution_amount, joined_at, status, password,
     * parent_member_number, parent_member_email, portal_pin, contribution_arrears_cutoff_date,
     * cutoff_cash_balance, cutoff_fund_balance
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

        foreach ($rows as $index => $row) {
            $lineNumber = $lineBase + $index;

            try {
                $result = $this->importRow($row, $defaultPassword, $defaultArrearsCutoffDate);

                if ($result === 'skipped') {
                    $skipped++;
                } else {
                    $created++;
                }
            } catch (Throwable $e) {
                $failed++;
                $errors[] = "Row {$lineNumber}: {$e->getMessage()}";
            }
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
        $email = strtolower(trim($this->cell($row, 'email')));

        if ($email === '') {
            throw new InvalidArgumentException(__('email is required.'));
        }

        $validator = Validator::make(['email' => $email], ['email' => 'required|email']);

        if ($validator->fails()) {
            throw new InvalidArgumentException(__('Invalid email address.'));
        }

        if ($name === '') {
            throw new InvalidArgumentException(__('name is required.'));
        }

        $memberNumber = $this->cell($row, 'member_number');

        if ($memberNumber !== '' && Member::query()->where('member_number', $memberNumber)->exists()) {
            return 'skipped';
        }

        if (Member::query()->where('email', $email)->exists() || User::query()->where('email', $email)->exists()) {
            return 'skipped';
        }

        $password = $this->cell($row, 'password');
        $plainPassword = strlen($password) >= 8 ? $password : $defaultPassword;

        $parentMember = $this->resolveParentMember($row);
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

            $member = $this->householdMembers->createFromAdmin($attributes, $plainPassword);

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
     * @param  array<string, string>  $row
     */
    private function resolveParentMember(array $row): ?Member
    {
        $parentNumber = $this->cell($row, 'parent_member_number');

        if ($parentNumber !== '') {
            $parent = Member::query()->where('member_number', $parentNumber)->first();

            if ($parent === null) {
                throw new InvalidArgumentException(__('Parent member number :number was not found.', ['number' => $parentNumber]));
            }

            return $parent;
        }

        foreach (['parent_member_email', 'parent_email'] as $key) {
            $parentEmail = strtolower(trim($this->cell($row, $key)));

            if ($parentEmail === '') {
                continue;
            }

            $parent = Member::query()->where('email', $parentEmail)->first();

            if ($parent === null) {
                throw new InvalidArgumentException(__('Parent member email :email was not found.', ['email' => $parentEmail]));
            }

            return $parent;
        }

        return null;
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
        $normalized = strtolower(trim($value));

        if ($normalized === '') {
            return 'active';
        }

        if (array_key_exists($normalized, Member::statusOptions())) {
            return $normalized;
        }

        throw new InvalidArgumentException(
            __('status must be one of: :statuses.', ['statuses' => implode(', ', Member::STATUSES)])
        );
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
        $headers = str_getcsv((string) $headerLine);
        $headers = array_map(fn ($header) => strtolower(trim(str_replace(' ', '_', (string) $header))), $headers);

        $rows = [];

        foreach ($lines as $line) {
            $cells = str_getcsv((string) $line);
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
