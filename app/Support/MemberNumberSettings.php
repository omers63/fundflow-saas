<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

final class MemberNumberSettings
{
    public const GROUP = 'member_number';

    public const FORMAT_FORMATTED = 'formatted';

    public const FORMAT_SEQUENTIAL = 'sequential';

    public const SEPARATOR_HYPHEN = '-';

    public const SEPARATOR_NONE = '';

    public const SEPARATOR_SLASH = '/';

    public const SEPARATOR_DOT = '.';

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'format' => self::FORMAT_FORMATTED,
            'prefix' => 'MEM',
            'separator' => self::SEPARATOR_HYPHEN,
            'padding' => 4,
            'include_year' => false,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function formatOptions(): array
    {
        return Lang::transOptions([
            self::FORMAT_FORMATTED => 'Formatted (prefix, separator, optional year)',
            self::FORMAT_SEQUENTIAL => 'Sequential (1, 2, 3…)',
        ]);
    }

    /**
     * @return array<string, string>
     */
    public static function separatorOptions(): array
    {
        return Lang::transOptions([
            self::SEPARATOR_HYPHEN => 'Hyphen (MEM-0001)',
            self::SEPARATOR_NONE => 'None (MEM0001)',
            self::SEPARATOR_SLASH => 'Slash (MEM/0001)',
            self::SEPARATOR_DOT => 'Dot (MEM.0001)',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        $stored = Setting::getGroup(self::GROUP);

        return [
            'format' => self::normalizeFormat($stored['format'] ?? self::defaults()['format']),
            'prefix' => self::normalizePrefix((string) ($stored['prefix'] ?? self::defaults()['prefix'])),
            'separator' => self::normalizeSeparator($stored['separator'] ?? self::defaults()['separator']),
            'padding' => self::normalizePadding($stored['padding'] ?? self::defaults()['padding']),
            'include_year' => filter_var($stored['include_year'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public static function save(array $values): void
    {
        $merged = array_merge(self::all(), $values);

        Setting::set(self::GROUP, 'format', self::normalizeFormat($merged['format']));
        Setting::set(self::GROUP, 'prefix', self::normalizePrefix((string) $merged['prefix']));
        Setting::set(self::GROUP, 'separator', self::normalizeSeparator($merged['separator']));
        Setting::set(self::GROUP, 'padding', self::normalizePadding($merged['padding']));
        Setting::set(
            self::GROUP,
            'include_year',
            filter_var($merged['include_year'], FILTER_VALIDATE_BOOLEAN) ? '1' : '0',
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public static function preview(array $overrides = []): string
    {
        $instance = self::fromConfig(array_merge(self::all(), $overrides));

        return $instance->compose($instance->nextSequence());
    }

    public static function generate(): string
    {
        $instance = self::fromConfig(self::all());

        return $instance->compose($instance->nextSequence());
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function fromConfig(array $config): self
    {
        return new self(
            format: self::normalizeFormat($config['format'] ?? self::defaults()['format']),
            prefix: self::normalizePrefix((string) ($config['prefix'] ?? self::defaults()['prefix'])),
            separator: self::normalizeSeparator($config['separator'] ?? self::defaults()['separator']),
            padding: self::normalizePadding($config['padding'] ?? self::defaults()['padding']),
            includeYear: filter_var($config['include_year'] ?? false, FILTER_VALIDATE_BOOLEAN),
        );
    }

    private function __construct(
        private readonly string $format,
        private readonly string $prefix,
        private readonly string $separator,
        private readonly int $padding,
        private readonly bool $includeYear,
    ) {}

    public function compose(int $sequence, ?Carbon $at = null): string
    {
        if ($this->format === self::FORMAT_SEQUENTIAL) {
            return (string) $sequence;
        }

        $at ??= BusinessDay::now();

        $parts = [
            $this->prefix,
        ];

        if ($this->includeYear) {
            $parts[] = $at->format('Y');
        }

        $parts[] = str_pad((string) $sequence, $this->padding, '0', STR_PAD_LEFT);

        if ($this->separator === '') {
            return implode('', $parts);
        }

        return implode($this->separator, $parts);
    }

    public function nextSequence(?Carbon $at = null): int
    {
        return $this->maxMatchingSequence($at) + 1;
    }

    private function maxMatchingSequence(?Carbon $at = null): int
    {
        $at ??= BusinessDay::now();

        if ($this->format === self::FORMAT_SEQUENTIAL) {
            return (int) (Member::query()
                ->whereNotNull('member_number')
                ->whereRaw("member_number REGEXP '^[0-9]+$'")
                ->selectRaw('MAX(CAST(member_number AS UNSIGNED)) as max_seq')
                ->value('max_seq') ?? 0);
        }

        $pattern = $this->matchingMysqlPattern($at);

        return (int) (Member::query()
            ->whereNotNull('member_number')
            ->whereRaw('member_number REGEXP ?', [$pattern])
            ->selectRaw('MAX(CAST(RIGHT(member_number, ?) AS UNSIGNED)) as max_seq', [$this->padding])
            ->value('max_seq') ?? 0);
    }

    public function parseSequence(string $memberNumber, ?Carbon $at = null): ?int
    {
        if ($this->format === self::FORMAT_SEQUENTIAL) {
            if (! preg_match('/^(\d+)$/', $memberNumber, $matches)) {
                return null;
            }

            return (int) $matches[1];
        }

        $at ??= BusinessDay::now();
        $pattern = $this->matchingPattern($at);

        if (! preg_match($pattern, $memberNumber, $matches)) {
            return null;
        }

        return (int) $matches[1];
    }

    private function matchingPattern(Carbon $at): string
    {
        return '/^'.$this->matchingBodyPattern($at, capture: true).'$/';
    }

    private function matchingMysqlPattern(Carbon $at): string
    {
        return '^'.$this->matchingBodyPattern($at, capture: false).'$';
    }

    private function matchingBodyPattern(Carbon $at, bool $capture): string
    {
        $prefix = preg_quote($this->prefix, '/');
        $sep = $this->separator !== '' ? preg_quote($this->separator, '/') : '';
        $digits = $capture
            ? '(\d{'.$this->padding.'})'
            : '[0-9]{'.$this->padding.'}';

        if ($this->includeYear) {
            $year = preg_quote($at->format('Y'), '/');

            if ($sep !== '') {
                return $prefix.$sep.$year.$sep.$digits;
            }

            return $prefix.$year.$digits;
        }

        if ($sep !== '') {
            return $prefix.$sep.$digits;
        }

        return $prefix.$digits;
    }

    private static function normalizePrefix(string $prefix): string
    {
        $prefix = Str::upper(Str::squish($prefix));

        if ($prefix === '') {
            return (string) self::defaults()['prefix'];
        }

        return Str::limit($prefix, 20, '');
    }

    private static function normalizeSeparator(mixed $separator): string
    {
        $allowed = [
            self::SEPARATOR_HYPHEN,
            self::SEPARATOR_NONE,
            self::SEPARATOR_SLASH,
            self::SEPARATOR_DOT,
        ];

        return in_array($separator, $allowed, true)
            ? $separator
            : self::defaults()['separator'];
    }

    private static function normalizePadding(mixed $padding): int
    {
        $padding = (int) $padding;

        return max(3, min(8, $padding));
    }

    private static function normalizeFormat(mixed $format): string
    {
        return in_array($format, [self::FORMAT_FORMATTED, self::FORMAT_SEQUENTIAL], true)
            ? $format
            : self::defaults()['format'];
    }

    /**
     * SQL expression that orders member numbers by their numeric sequence (not lexicographically).
     */
    public static function sequenceOrderExpression(string $qualifiedColumn = 'member_number'): string
    {
        $config = self::all();

        if ($config['format'] === self::FORMAT_SEQUENTIAL) {
            return "CAST({$qualifiedColumn} AS UNSIGNED)";
        }

        $padding = self::normalizePadding($config['padding']);

        return "CAST(RIGHT({$qualifiedColumn}, {$padding}) AS UNSIGNED)";
    }

    public static function applySequenceOrder(
        Builder $query,
        string $direction,
        string $qualifiedColumn = 'member_number',
    ): Builder {
        $direction = strtolower($direction) === 'desc' ? 'desc' : 'asc';

        return $query
            ->orderByRaw(self::sequenceOrderExpression($qualifiedColumn).' '.$direction)
            ->orderBy($qualifiedColumn, $direction);
    }

    public static function applyOrderByMemberIdColumn(
        Builder $query,
        string $direction,
        string $memberIdColumn,
    ): Builder {
        $direction = strtolower($direction) === 'desc' ? 'desc' : 'asc';
        $expression = self::sequenceOrderExpression('members.member_number');

        return $query->orderBy(
            Member::query()
                ->selectRaw($expression)
                ->whereColumn('members.id', $memberIdColumn)
                ->limit(1),
            $direction,
        );
    }

    public static function applyOrderByLoanInstallmentMember(Builder $query, string $direction): Builder
    {
        $direction = strtolower($direction) === 'desc' ? 'desc' : 'asc';
        $expression = self::sequenceOrderExpression('members.member_number');

        return $query->orderBy(
            Member::query()
                ->selectRaw($expression)
                ->join('loans', 'loans.member_id', '=', 'members.id')
                ->whereColumn('loans.id', 'loan_installments.loan_id')
                ->limit(1),
            $direction,
        );
    }
}
