<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\Carbon;

/**
 * Re-localize ledger descriptions stored in English at post time for member-facing UI.
 */
final class MemberLedgerDescriptionTranslator
{
    public static function localize(?string $description, int $depth = 0): string
    {
        $description = trim((string) ($description ?? ''));

        if ($description === '') {
            return '—';
        }

        if ($depth > 4) {
            return $description;
        }

        $direct = __($description);

        if ($direct !== $description) {
            return $direct;
        }

        foreach (self::rules($depth) as $rule) {
            if (preg_match($rule['pattern'], $description, $matches) !== 1) {
                continue;
            }

            return $rule['build']($matches);
        }

        return $description;
    }

    public static function localizePeriod(string $period): string
    {
        $period = trim($period);

        if ($period === '' || $period === '—') {
            return '—';
        }

        foreach (['M Y', 'F Y', 'M j, Y', 'j M Y'] as $format) {
            try {
                $date = Carbon::createFromFormat($format, $period);

                return MemberDateDisplay::format($date, $format) ?? $period;
            } catch (\Throwable) {
                continue;
            }
        }

        return $period;
    }

    /**
     * @return array<int, array{pattern: string, build: callable(array<int, string>): string}>
     */
    private static function rules(int $depth): array
    {
        $next = static fn (string $value): string => self::localize($value, $depth + 1);

        return [
            [
                'pattern' => '/^Contribution — (.+)$/',
                'build' => fn (array $m): string => __('Contribution — :period', [
                    'period' => self::localizePeriod($m[1]),
                ]),
            ],
            [
                'pattern' => '/^Contribution late fee — (.+)$/',
                'build' => fn (array $m): string => __('Contribution late fee — :period', [
                    'period' => self::localizePeriod($m[1]),
                ]),
            ],
            [
                'pattern' => '/^Contribution reversal — (.+)$/',
                'build' => fn (array $m): string => __('Contribution reversal — :period', [
                    'period' => self::localizePeriod($m[1]),
                ]),
            ],
            [
                'pattern' => '/^Late fee reversal — (.+)$/',
                'build' => fn (array $m): string => __('Late fee reversal — :period', [
                    'period' => self::localizePeriod($m[1]),
                ]),
            ],
            [
                'pattern' => '/^Allocation — (.+)$/',
                'build' => fn (array $m): string => __('Allocation — :period', [
                    'period' => self::localizePeriod($m[1]),
                ]),
            ],
            [
                'pattern' => '/^Transfer to (.+?) — (.+)$/',
                'build' => fn (array $m): string => trim(__('Transfer to :name', ['name' => $m[1]]).' — '.$next($m[2])),
            ],
            [
                'pattern' => '/^Transfer from (.+?) — (.+)$/',
                'build' => fn (array $m): string => trim(__('Transfer from :name', ['name' => $m[1]]).' — '.$next($m[2])),
            ],
            [
                'pattern' => '/^Transfer to (.+)$/',
                'build' => fn (array $m): string => __('Transfer to :name', ['name' => $m[1]]),
            ],
            [
                'pattern' => '/^Transfer from (.+)$/',
                'build' => fn (array $m): string => __('Transfer from :name', ['name' => $m[1]]),
            ],
            [
                'pattern' => '/^EMI late fee — loan #(\d+) inst\. (\d+)$/',
                'build' => fn (array $m): string => __('EMI late fee — loan #:id inst. :num', [
                    'id' => $m[1],
                    'num' => $m[2],
                ]),
            ],
            [
                'pattern' => '/^EMI late fee reversal — loan #(\d+) inst\. (\d+)$/',
                'build' => fn (array $m): string => __('EMI late fee reversal — loan #:id inst. :num', [
                    'id' => $m[1],
                    'num' => $m[2],
                ]),
            ],
            [
                'pattern' => '/^EMI late fee — (.+)$/',
                'build' => fn (array $m): string => __('EMI late fee — :details', [
                    'details' => $next($m[1]),
                ]),
            ],
            [
                'pattern' => '/^Loan #(\d+) disbursement \(#(\d+)\) – (.+)$/',
                'build' => fn (array $m): string => __('Loan #:id disbursement (#:seq) – :name', [
                    'id' => $m[1],
                    'seq' => $m[2],
                    'name' => $m[3],
                ]),
            ],
            [
                'pattern' => '/^Loan #(\d+) repayment \(installment #(\d+)\) – (.+)$/',
                'build' => fn (array $m): string => __('Loan #:id repayment (installment #:num) – :name', [
                    'id' => $m[1],
                    'num' => $m[2],
                    'name' => $m[3],
                ]),
            ],
            [
                'pattern' => '/^Loan #(\d+) installment #(\d+)$/',
                'build' => fn (array $m): string => __('Loan #:id installment #:num', [
                    'id' => $m[1],
                    'num' => $m[2],
                ]),
            ],
            [
                'pattern' => '/^Guarantor default – loan #(\d+) installment #(\d+)$/',
                'build' => fn (array $m): string => __('Guarantor default – loan #:id installment #:num', [
                    'id' => $m[1],
                    'num' => $m[2],
                ]),
            ],
            [
                'pattern' => '/^Loan #(\d+) — excess fund to cash$/',
                'build' => fn (array $m): string => __('Loan #:id — excess fund to cash', ['id' => $m[1]]),
            ],
            [
                'pattern' => '/^Loan #(\d+) repayments \(import, bulk\) – (.+)$/',
                'build' => fn (array $m): string => __('Loan #:id repayments (import, bulk) – :name', [
                    'id' => $m[1],
                    'name' => $m[2],
                ]),
            ],
            [
                'pattern' => '/^Loan #(\d+) – (.+) (.+)$/',
                'build' => fn (array $m): string => __('Loan #:id – :name :marker', [
                    'id' => $m[1],
                    'name' => $m[2],
                    'marker' => $m[3],
                ]),
            ],
            [
                'pattern' => '/^Deposit #(\d+) by (.+)$/',
                'build' => fn (array $m): string => __('Deposit #:id by :name', [
                    'id' => $m[1],
                    'name' => $m[2],
                ]),
            ],
            [
                'pattern' => '/^Deposit by (.+)$/',
                'build' => fn (array $m): string => __('Deposit by :name', ['name' => $m[1]]),
            ],
            [
                'pattern' => '/^Posted: (.+)$/',
                'build' => fn (array $m): string => __('Posted: :description', [
                    'description' => $next($m[1]),
                ]),
            ],
            [
                'pattern' => '/^Bank: (.+)$/',
                'build' => fn (array $m): string => __('Bank: :description', [
                    'description' => $next($m[1]),
                ]),
            ],
            [
                'pattern' => '/^Bank import #(\d+) \((.+)\) — (.+)$/',
                'build' => fn (array $m): string => __('Bank import #:id (:date) — :description', [
                    'id' => $m[1],
                    'date' => self::localizePeriod($m[2]),
                    'description' => $next($m[3]),
                ]),
            ],
            [
                'pattern' => '/^Bank import #(\d+)$/',
                'build' => fn (array $m): string => __('Bank import #:id', ['id' => $m[1]]),
            ],
            [
                'pattern' => '/^Cash out #(\d+) – (.+)$/',
                'build' => fn (array $m): string => __('Cash out #:id – :name', [
                    'id' => $m[1],
                    'name' => $m[2],
                ]),
            ],
            [
                'pattern' => '/^Reversal of #(\d+): (.+) — (.+)$/',
                'build' => fn (array $m): string => __('Reversal of #:id: :original — :reason', [
                    'id' => $m[1],
                    'original' => $next($m[2]),
                    'reason' => $m[3],
                ]),
            ],
            [
                'pattern' => '/^Refund — (.+) — (.+)$/',
                'build' => fn (array $m): string => __('Refund — :member — :reason', [
                    'member' => $m[1],
                    'reason' => $m[2],
                ]),
            ],
            [
                'pattern' => '/^Annual subscription fee — (.+)$/',
                'build' => fn (array $m): string => __('Annual subscription fee — :name', ['name' => $m[1]]),
            ],
            [
                'pattern' => '/^(.+) — cash — (.+)$/',
                'build' => fn (array $m): string => __(':label — cash — :name', [
                    'label' => $m[1],
                    'name' => $m[2],
                ]),
            ],
            [
                'pattern' => '/^(.+) — fund — (.+)$/',
                'build' => fn (array $m): string => __(':label — fund — :name', [
                    'label' => $m[1],
                    'name' => $m[2],
                ]),
            ],
        ];
    }
}
