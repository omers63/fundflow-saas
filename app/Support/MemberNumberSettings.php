<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use Carbon\Carbon;
use Illuminate\Support\Str;

final class MemberNumberSettings
{
    public const GROUP = 'member_number';

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
            'prefix' => 'MEM',
            'separator' => self::SEPARATOR_HYPHEN,
            'padding' => 4,
            'include_year' => false,
        ];
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
        Setting::set(self::GROUP, 'prefix', self::normalizePrefix((string) ($values['prefix'] ?? self::defaults()['prefix'])));
        Setting::set(self::GROUP, 'separator', self::normalizeSeparator($values['separator'] ?? self::defaults()['separator']));
        Setting::set(self::GROUP, 'padding', self::normalizePadding($values['padding'] ?? self::defaults()['padding']));
        Setting::set(
            self::GROUP,
            'include_year',
            filter_var($values['include_year'] ?? false, FILTER_VALIDATE_BOOLEAN) ? '1' : '0',
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public static function preview(array $overrides = []): string
    {
        $config = array_merge(self::all(), $overrides);

        return self::fromConfig($config)->compose(self::fromConfig($config)->nextSequence());
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
            prefix: self::normalizePrefix((string) ($config['prefix'] ?? self::defaults()['prefix'])),
            separator: self::normalizeSeparator($config['separator'] ?? self::defaults()['separator']),
            padding: self::normalizePadding($config['padding'] ?? self::defaults()['padding']),
            includeYear: filter_var($config['include_year'] ?? false, FILTER_VALIDATE_BOOLEAN),
        );
    }

    private function __construct(
        private readonly string $prefix,
        private readonly string $separator,
        private readonly int $padding,
        private readonly bool $includeYear,
    ) {}

    public function compose(int $sequence, ?Carbon $at = null): string
    {
        $at ??= now();

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
        $at ??= now();
        $max = 0;

        foreach (Member::query()->pluck('member_number') as $memberNumber) {
            $parsed = $this->parseSequence((string) $memberNumber, $at);

            if ($parsed !== null) {
                $max = max($max, $parsed);
            }
        }

        return $max + 1;
    }

    public function parseSequence(string $memberNumber, ?Carbon $at = null): ?int
    {
        $at ??= now();
        $pattern = $this->matchingPattern($at);

        if (! preg_match($pattern, $memberNumber, $matches)) {
            return null;
        }

        return (int) $matches[1];
    }

    private function matchingPattern(Carbon $at): string
    {
        $prefix = preg_quote($this->prefix, '/');
        $sep = $this->separator !== '' ? preg_quote($this->separator, '/') : '';
        $digits = $this->padding;

        if ($this->includeYear) {
            $year = preg_quote($at->format('Y'), '/');

            if ($sep !== '') {
                return '/^'.$prefix.$sep.$year.$sep.'(\d{'.$digits.'})$/';
            }

            return '/^'.$prefix.$year.'(\d{'.$digits.'})$/';
        }

        if ($sep !== '') {
            return '/^'.$prefix.$sep.'(\d{'.$digits.'})$/';
        }

        return '/^'.$prefix.'(\d{'.$digits.'})$/';
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
}
