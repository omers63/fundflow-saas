@php
use App\Filament\Support\MoneyDisplay;
use App\Models\Tenant\Loan;
use App\Support\PublicPageSettings;
use App\Support\StatementSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

$d = $statement->details ?? [];
$m = $d['member_snapshot'] ?? [];
$currency = $d['currency'] ?? 'USD';
$accent = $cfg['accent_color'] ?? '#059669';
$isArabic = app()->getLocale() === 'ar';
$moneyHtml = function (float $amount, bool $signed = false, bool $asBalance = false, ?string $tone = null, ?string $symbolFill = null, ) use ($currency): string {
    return MoneyDisplay::pdfHtml(
        $amount,
        $currency,
        signed: $signed,
        tone: $tone,
        colorBySign: $asBalance,
        symbolFill: $symbolFill,
    )?->toHtml() ?? '—';
};
$fundName = (string) ($cfg['fund_name'] ?? $cfg['brand'] ?? config('app.name'));
$fundNameEn = trim((string) ($cfg['fund_name_en'] ?? $d['fund_name_en'] ?? ''));
$fundNameAr = trim((string) ($cfg['fund_name_ar'] ?? $d['fund_name_ar'] ?? ''));
if ($fundNameEn === '') {
    $fundNameEn = PublicPageSettings::fundName(locale: 'en');
}
if ($fundNameAr === '') {
    $fundNameAr = PublicPageSettings::fundName(locale: 'ar');
}
$pageFooterParts = [];
if ($fundNameEn !== '') {
    $pageFooterParts[] = '<span dir="ltr">' . e($fundNameEn) . '</span>';
}
if ($fundNameAr !== '' && $fundNameAr !== $fundNameEn) {
    $pageFooterParts[] = e($fundNameAr);
}
$pageFooterHtml = $pageFooterParts === [] ? '' : implode(' · ', $pageFooterParts);
$pageFooterNeedsAmiri = $fundNameAr !== ''
    && StatementSettings::customFontPath(StatementSettings::FONT_AMIRI) !== null;
$pageFooterFont = $pageFooterNeedsAmiri
    ? 'Amiri'
    : ($pdfFont ?? StatementSettings::pdfFontFamily());
$loans = $d['loans'] ?? (isset($d['active_loan']) && is_array($d['active_loan']) ? [$d['active_loan']] : []);
$yearly = $d['yearly_history'] ?? [];
$months = $d['current_year_months'] ?? [];
$yearTotals = $d['current_year_totals'] ?? [];
$lifetime = $d['lifetime'] ?? [];
$fees = $d['fees'] ?? ['total' => 0, 'groups' => []];
$asOf = $d['as_of'] ?? $statement->generated_at?->toDateString() ?? now()->toDateString();

$periodParts = explode('-', (string) $statement->period);
$periodYear = $periodParts[0] ?? '';
$periodMonth = Carbon::create((int) ($periodParts[0] ?? 2000), (int) ($periodParts[1] ?? 1), 1)
    ->locale(app()->getLocale())
    ->translatedFormat('F');

$periodValueHtml = $isArabic
    ? '<span dir="ltr">' . e($periodYear) . '</span> ' . e($periodMonth)
    : e($periodMonth) . ' <span dir="ltr">' . e($periodYear) . '</span>';
$asOfValueHtml = '<span dir="ltr">' . e($asOf) . '</span>';

$phoneRows = array_filter([
    ['label' => __('Mobile'), 'value' => $m['mobile_phone'] ?? $m['phone'] ?? null],
    ['label' => __('Home'), 'value' => $m['home_phone'] ?? null],
    ['label' => __('Work'), 'value' => $m['work_phone'] ?? null],
], fn(array $row): bool => filled($row['value']));

$monthName = fn(int $month): string => Carbon::create(2000, $month, 1)->translatedFormat('M');

/** DomPDF does not reverse table columns for dir=rtl — mirror cell order manually. */
$rtlCells = function (array $cells) use ($isArabic): array {
    return $isArabic ? array_reverse(array_values($cells)) : array_values($cells);
};

$kpiCards = [
    [
        'label' => __('Opening balance'),
        'value' => $moneyHtml((float) $statement->opening_balance, asBalance: true),
        'accent' => false,
    ],
    [
        'label' => __('Contributions'),
        'value' => $moneyHtml((float) $statement->total_contributions),
        'accent' => false,
    ],
    [
        'label' => __('Loan repayments'),
        'value' => $moneyHtml((float) $statement->total_repayments),
        'accent' => false,
    ],
    [
        'label' => __('Closing balance'),
        'value' => $moneyHtml((float) $statement->closing_balance, asBalance: true),
        'accent' => false,
    ],
];
$kpiCards = $rtlCells($kpiCards);

$memberMetaRows = [
    ['label' => __('Name'), 'html' => e($m['name'] ?? '—')],
    ['label' => __('Member number'), 'html' => e($m['member_number'] ?? '—')],
    ['label' => __('Status'), 'html' => e(__(ucfirst((string) ($m['status'] ?? '—'))))],
    ['label' => __('Joined'), 'html' => e($m['joined_at'] ?? '—')],
    ['label' => __('Email'), 'html' => e($m['email'] ?? '—')],
];

if ($phoneRows === []) {
    $memberMetaRows[] = ['label' => __('Phone'), 'html' => e($m['phone'] ?? '—')];
} else {
    foreach ($phoneRows as $phone) {
        $memberMetaRows[] = [
            'label' => $phone['label'],
            'html' => '<span dir="ltr">' . e($phone['value']) . '</span>',
        ];
    }
}

$fundClosing = (float) ($d['fund_closing'] ?? 0);
$fundPillTone = match (true) {
    $fundClosing < 0 => 'danger',
    $fundClosing > 0 => 'success',
    default => 'neutral',
};
$bankingMetaRows = [
    ['label' => __('IBAN'), 'html' => '<span dir="ltr">' . e($m['iban'] ?? '—') . '</span>'],
    ['label' => __('Account number'), 'html' => '<span dir="ltr">' . e($m['bank_account_number'] ?? '—') . '</span>'],
    ['label' => __('Monthly contribution'), 'html' => $moneyHtml((float) ($m['monthly_contrib'] ?? 0))],
    ['label' => __('Cash at period end'), 'html' => $moneyHtml((float) ($d['cash_closing'] ?? 0), asBalance: true)],
    [
        'label' => __('Fund at period end'),
        'html' => '<span class="stmt-balance-pill stmt-balance-pill--' . $fundPillTone . '">'
            . $moneyHtml(
                $fundClosing,
                symbolFill: in_array($fundPillTone, ['danger', 'success'], true) ? '#ffffff' : null,
            )
            . '</span>',
    ],
];

$detailCards = [
    ['title' => __('Member'), 'rows' => $memberMetaRows],
    ['title' => __('Banking & balances'), 'rows' => $bankingMetaRows],
];
$detailCards = $rtlCells($detailCards);

$activityMonthCount = max(1, (int) ($yearTotals['month_count'] ?? count($months)));
$activityFromMonth = (int) ($yearTotals['from_month'] ?? ($months[0]['month'] ?? 1));
$activityFromYear = (int) ($yearTotals['from_year'] ?? ($months[0]['year'] ?? (int) Str::before((string) $statement->period, '-')));
$activityToMonth = (int) ($yearTotals['to_month'] ?? ($months[array_key_last($months)]['month'] ?? $activityFromMonth));
$activityToYear = (int) ($yearTotals['to_year'] ?? ($months[array_key_last($months)]['year'] ?? $activityFromYear));
$activityPeriodLabel = fn(int $month, int $year): string => Carbon::create($year, $month, 1)
    ->locale(app()->getLocale())
    ->translatedFormat('M-Y');
$activityFromLabel = $activityPeriodLabel($activityFromMonth, $activityFromYear);
$activityToLabel = $activityPeriodLabel($activityToMonth, $activityToYear);
$activityRangeHtml = '<span dir="ltr">' . e($activityFromLabel) . '</span> ' . e(__('to')) . ' <span dir="ltr">' . e($activityToLabel) . '</span>';
$activityTitle = __(':count-Month Activity', ['count' => $activityMonthCount]);

/**
 * DomPDF does not apply Unicode bidi: for Arabic, emit LTR/meta runs before the Arabic title
 * so the title paints on the right of brackets, dates, and Latin digits.
 */
$sectionHeading = function (string $title, ?string $metaInnerHtml = null, bool $brackets = true) use ($isArabic): string {
    $titleHtml = e($title);
    if ($metaInnerHtml === null || $metaInnerHtml === '') {
        return $titleHtml;
    }
    $metaBody = $brackets ? '[' . $metaInnerHtml . ']' : $metaInnerHtml;
    $metaHtml = '<span class="section-title__meta">' . $metaBody . '</span>';

    return $isArabic ? $metaHtml . ' ' . $titleHtml : $titleHtml . ' ' . $metaHtml;
};

$joinedAt = (string) ($m['joined_at'] ?? '—');
$yearMetaInner = $isArabic
    ? '<span dir="ltr">' . e($joinedAt) . '</span> · ' . e(__('Since membership year'))
    : e(__('Since membership year')) . ' · <span dir="ltr">' . e($joinedAt) . '</span>';

$lifetimeHeadingHtml = $isArabic
    // DomPDF paints LTR: date → Summary as of → Lifetime summary so RTL reads title → Summary as of → date.
    ? '<span class="section-title__meta"><span dir="ltr">'.e($asOf).'</span> : '.e(__('Summary as of')).'</span> '
        .e(__('Lifetime summary'))
    : e(__('Lifetime summary'))
        .' <span class="section-title__meta">'
        .e(__('Summary as of :date', ['date' => $asOf]))
        .'</span>';

$activityColumns = [
    ['label' => __('Month'), 'key' => 'month'],
    ['label' => __('Date'), 'key' => 'date'],
    ['label' => __('Contributions'), 'key' => 'contributions'],
    ['label' => __('Repayments'), 'key' => 'repayments'],
];
$activityColumns = $rtlCells($activityColumns);

$yearlyColumns = [
    ['label' => __('Year'), 'key' => 'year'],
    ['label' => __('Contributions'), 'key' => 'contributions'],
    ['label' => __('Repayments'), 'key' => 'repayments'],
    ['label' => __('Total'), 'key' => 'total'],
    ['label' => __('Cash balance'), 'key' => 'cash_balance'],
    ['label' => __('Fund balance'), 'key' => 'fund_balance'],
];
$yearlyColumns = $rtlCells($yearlyColumns);

$loanColumns = [
    ['label' => __('Loan #'), 'key' => 'id'],
    ['label' => __('Amount'), 'key' => 'amount'],
    ['label' => __('EMI'), 'key' => 'emi'],
    ['label' => __('Disbursed'), 'key' => 'disbursed'],
    ['label' => __('Status'), 'key' => 'status'],
    ['label' => __('Progress'), 'key' => 'progress'],
];
$loanColumns = $rtlCells($loanColumns);

$lifetimeCards = [
    [
        'label' => __('Contributions'),
        'value' => $moneyHtml((float) ($lifetime['total_contributions'] ?? 0)),
    ],
    [
        'label' => __('Loan repayments'),
        'value' => $moneyHtml((float) ($lifetime['total_repayments'] ?? 0)),
    ],
    [
        'label' => __('Collection'),
        'value' => $moneyHtml((float) ($lifetime['collection_total'] ?? (
            (float) ($lifetime['total_contributions'] ?? 0) + (float) ($lifetime['total_repayments'] ?? 0)
        ))),
    ],
    [
        'label' => (static function () use ($isArabic, $lifetime): string {
            $count = (string) ((int) ($lifetime['loan_count'] ?? 0));
            $pill = '<span class="stmt-kpi-pill" dir="ltr">'.e($count).'</span>';
            $title = e(__('Loans'));

            return $isArabic ? $pill.' '.$title : $title.' '.$pill;
        })(),
        'value' => $moneyHtml((float) ($lifetime['loan_amount'] ?? 0)),
    ],
    [
        'label' => __('Cash balance'),
        'value' => $moneyHtml((float) ($lifetime['cash_balance'] ?? $d['cash_closing'] ?? 0), asBalance: true),
    ],
    [
        'label' => __('Fund balance'),
        'value' => $moneyHtml((float) ($lifetime['fund_balance'] ?? $d['fund_closing'] ?? 0), asBalance: true),
    ],
];
$lifetimeCards = $rtlCells($lifetimeCards);

$feeColumns = [
    ['label' => __('Fee type'), 'key' => 'type'],
    ['label' => __('Amount'), 'key' => 'amount'],
];
$feeColumns = $rtlCells($feeColumns);

$txnAccountLabel = function (mixed $accountType): string {
    return match ((string) $accountType) {
        'cash' => __('Cash'),
        'fund' => __('Fund'),
        default => __(ucfirst((string) ($accountType ?: 'Unknown'))),
    };
};

$txnColumns = [
    ['label' => __('Date'), 'key' => 'date'],
    ['label' => __('Description'), 'key' => 'description'],
    ['label' => __('Account'), 'key' => 'account'],
    ['label' => __('Type'), 'key' => 'type'],
    ['label' => __('Amount'), 'key' => 'amount'],
];
$txnColumns = $rtlCells($txnColumns);

$contribColumns = [
    ['label' => __('Date'), 'key' => 'date'],
    ['label' => __('Amount'), 'key' => 'amount'],
    ['label' => __('Notes'), 'key' => 'notes'],
];
$contribColumns = $rtlCells($contribColumns);

$emiColumns = [
    ['label' => __('Loan #'), 'key' => 'loan_id'],
    ['label' => __('EMI #'), 'key' => 'installment_number'],
    ['label' => __('Due'), 'key' => 'due'],
    ['label' => __('Paid'), 'key' => 'paid'],
    ['label' => __('Amount'), 'key' => 'amount'],
];
$emiColumns = $rtlCells($emiColumns);
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ $isArabic ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="UTF-8">
    <title>{{ $fundName }} — {{ $statement->period_formatted }}</title>
    @include('pdf.partials.layout-styles', [
        'accent' => $accent,
        'isArabic' => $isArabic,
        'logoDataUri' => $logoDataUri ?? null,
        'pdfFont' => $pdfFont ?? \App\Support\StatementSettings::pdfFontFamily(),
        'pageFooterFont' => $pageFooterFont,
    ])
    @include('pdf.partials.statement-styles', ['accent' => $accent, 'isArabic' => $isArabic])
</head>
<body class="stmt-body" style="direction: {{ $isArabic ? 'rtl' : 'ltr' }}; text-align: {{ $isArabic ? 'right' : 'left' }};">
    {{-- Warm Amiri before the fixed footer so page-1 Arabic is not Helvetica (????). --}}
    @if ($pageFooterNeedsAmiri)
        <div class="stmt-font-warmup" aria-hidden="true">ع</div>
    @endif
    @if ($pageFooterHtml !== '')
        <div class="stmt-page-footer">{!! $pageFooterHtml !!}</div>
    @endif
    <div class="stmt-hero" style="border-color: {{ $accent }};">
        <table class="stmt-hero__table">
            <tr>
                @if ($isArabic)
                    <td class="stmt-hero__copy">
                        <div class="stmt-hero__eyebrow">{{ __('Monthly Account Statement') }}</div>
                        <div class="stmt-hero__fund">{{ $fundName }}</div>
                        {{-- Separate label/value cells so ArPHP shaping cannot reorder Period / As of lines. --}}
                        <table class="stmt-hero__meta">
                            <tr>
                                <td class="stmt-hero__meta-value">{!! $periodValueHtml !!}</td>
                                <td class="stmt-hero__meta-label">{{ __('Period') }}:</td>
                            </tr>
                            <tr>
                                <td class="stmt-hero__meta-value">{!! $asOfValueHtml !!}</td>
                                <td class="stmt-hero__meta-label">{{ __('As of') }}:</td>
                            </tr>
                        </table>
                    </td>
                    @if (!empty($logoDataUri))
                        <td class="stmt-hero__logo"><img src="{{ $logoDataUri }}" alt=""></td>
                    @endif
                @else
                    @if (!empty($logoDataUri))
                        <td class="stmt-hero__logo"><img src="{{ $logoDataUri }}" alt=""></td>
                    @endif
                    <td class="stmt-hero__copy">
                        <div class="stmt-hero__eyebrow">{{ __('Monthly Account Statement') }}</div>
                        <div class="stmt-hero__fund">{{ $fundName }}</div>
                        <table class="stmt-hero__meta">
                            <tr>
                                <td class="stmt-hero__meta-label">{{ __('Period') }}:</td>
                                <td class="stmt-hero__meta-value">{!! $periodValueHtml !!}</td>
                            </tr>
                            <tr>
                                <td class="stmt-hero__meta-label">{{ __('As of') }}:</td>
                                <td class="stmt-hero__meta-value">{!! $asOfValueHtml !!}</td>
                            </tr>
                        </table>
                    </td>
                @endif
            </tr>
        </table>
    </div>

    <table class="stmt-kpis">
        <tr>
            @foreach ($kpiCards as $card)
                <td @class(['stmt-kpi', 'stmt-kpi--accent' => $card['accent']]) @if ($card['accent']) style="background: {{ $accent }};" @endif>
                    <div class="stmt-kpi__label">{!! $card['label'] !!}</div>
                    <div class="stmt-kpi__value">{!! $card['value'] !!}</div>
                </td>
            @endforeach
        </tr>
    </table>

    <h2 class="section-title">{{ __('Member & account details') }}</h2>
    <table class="stmt-two-col">
        <tr>
            @foreach ($detailCards as $card)
                <td class="stmt-card">
                    <div class="stmt-card__title">{{ $card['title'] }}</div>
                    <table class="stmt-meta stmt-meta--{{ $isArabic ? 'ar' : 'en' }}">
                        @foreach ($card['rows'] as $row)
                            <tr>
                                @if ($isArabic)
                                    <td class="stmt-meta__value">{!! $row['html'] !!}</td>
                                    <td class="stmt-meta__label">{{ $row['label'] }}</td>
                                @else
                                    <td class="stmt-meta__label">{{ $row['label'] }}</td>
                                    <td class="stmt-meta__value">{!! $row['html'] !!}</td>
                                @endif
                            </tr>
                        @endforeach
                    </table>
                </td>
            @endforeach
        </tr>
    </table>

    @if (! empty($months))
        <h2 class="section-title">
            @if ($isArabic)
                <span class="section-title__meta">{!! $activityRangeHtml !!}</span> {{ $activityTitle }}
            @else
                {{ $activityTitle }} <span class="section-title__meta">{!! $activityRangeHtml !!}</span>
            @endif
        </h2>
        <table class="data-table">
            <thead>
                <tr>
                    @foreach ($activityColumns as $column)
                        <th>{{ $column['label'] }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($months as $row)
                    @php
                        $activityDates = collect(array_merge($row['contribution_dates'] ?? [], $row['repayment_dates'] ?? []))
                            ->filter()
                            ->unique()
                            ->sort()
                            ->values();
                        $activityDateHtml = $activityDates->isEmpty()
                            ? '—'
                            : '<span dir="ltr">'.e($activityDates->implode(', ')).'</span>';
                        $activityCells = $rtlCells([
                            ['class' => '', 'html' => e($monthName((int) $row['month']))],
                            ['class' => 'date', 'html' => $activityDateHtml],
                            ['class' => 'amount-col', 'html' => $moneyHtml((float) $row['contributions'])],
                            ['class' => 'amount-col', 'html' => $moneyHtml((float) $row['repayments'])],
                        ]);
                    @endphp
                    <tr>
                        @foreach ($activityCells as $cell)
                            <td @if ($cell['class'] !== '') class="{{ $cell['class'] }}" @endif>{!! $cell['html'] !!}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                @php
                    $activityFooterCells = $rtlCells([
                        ['class' => '', 'html' => '&nbsp;'],
                        ['class' => '', 'html' => '&nbsp;'],
                        [
                            'class' => 'amount-col',
                            'html' => '<div class="stmt-tfoot-pill">'.e(__(':count-Month contributions', ['count' => $activityMonthCount])).'</div>'.$moneyHtml((float) ($yearTotals['contributions'] ?? 0)),
                        ],
                        [
                            'class' => 'amount-col',
                            'html' => '<div class="stmt-tfoot-pill">'.e(__(':count-Month repayments', ['count' => $activityMonthCount])).'</div>'.$moneyHtml((float) ($yearTotals['repayments'] ?? 0)),
                        ],
                    ]);
                @endphp
                <tr>
                    @foreach ($activityFooterCells as $cell)
                        <td @if ($cell['class'] !== '') class="{{ $cell['class'] }}" @endif>{!! $cell['html'] !!}</td>
                    @endforeach
                </tr>
            </tfoot>
        </table>
    @endif

    @if (! empty($yearly))
        <h2 class="section-title">{!! $sectionHeading(__('Year-by-year summary'), $yearMetaInner, brackets: false) !!}</h2>
        <table class="data-table">
            <thead>
                <tr>
                    @foreach ($yearlyColumns as $column)
                        <th>{{ $column['label'] }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($yearly as $row)
                    @php
                        $contributions = (float) $row['contributions'];
                        $repayments = (float) $row['repayments'];
                        $total = $contributions + $repayments;
                        $cells = $rtlCells([
                            ['class' => '', 'html' => e((string) $row['year'])],
                            ['class' => 'amount-col', 'html' => $moneyHtml($contributions)],
                            ['class' => 'amount-col', 'html' => $moneyHtml($repayments)],
                            ['class' => 'amount-col', 'html' => $moneyHtml($total)],
                            ['class' => 'amount-col', 'html' => $moneyHtml((float) ($row['cash_balance'] ?? 0), asBalance: true)],
                            ['class' => 'amount-col', 'html' => $moneyHtml((float) ($row['fund_balance'] ?? 0), asBalance: true)],
                        ]);
                    @endphp
                    <tr>
                        @foreach ($cells as $cell)
                            <td @if ($cell['class'] !== '') class="{{ $cell['class'] }}" @endif>{!! $cell['html'] !!}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if (($cfg['include_loan'] ?? true) && ! empty($loans))
        <div class="stmt-section stmt-section--keep">
            <h2 class="section-title">{{ __('Loan history') }}</h2>
            <table class="data-table">
                <thead>
                    <tr>
                        @foreach ($loanColumns as $column)
                            <th>{{ $column['label'] }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($loans as $loan)
                        @php
                            $pct = (int) ($loan['repay_percent'] ?? 0);
                            $statusLabel = Loan::statusOptions()[$loan['status'] ?? ''] ?? __(ucfirst((string) ($loan['status'] ?? '—')));
                            $miniTrackClass = $isArabic ? 'stmt-mini-track stmt-mini-track--end' : 'stmt-mini-track';
                            $barHtml = '<span class="'.$miniTrackClass.'"><span class="stmt-mini-fill" style="width: '.max(3, $pct).'%; background: '.e($accent).';"></span></span>';
                            $pctHtml = '<span class="stmt-mini-pct" dir="ltr">'.$pct.'%</span>';
                            $inner = $isArabic ? $pctHtml.' '.$barHtml : $barHtml.' '.$pctHtml;
                            $progressHtml = '<table class="stmt-progress" dir="ltr" border="0" cellpadding="0" cellspacing="0">'
                                .'<tr><td class="stmt-progress__cell">'.$inner.'</td></tr></table>';
                            $cells = $rtlCells([
                                ['class' => '', 'html' => e('#'.($loan['id'] ?? '—'))],
                                ['class' => 'amount-col', 'html' => $moneyHtml((float) ($loan['amount_approved'] ?? 0))],
                                ['class' => 'amount-col', 'html' => $moneyHtml((float) ($loan['emi_amount'] ?? 0))],
                                ['class' => '', 'html' => e($loan['disbursed_at'] ?? '—')],
                                ['class' => '', 'html' => e($statusLabel)],
                                ['class' => 'stmt-progress-cell', 'html' => $progressHtml],
                            ]);
                        @endphp
                        <tr>
                            @foreach ($cells as $cell)
                                <td @if ($cell['class'] !== '') class="{{ $cell['class'] }}" @endif>{!! $cell['html'] !!}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <h2 class="section-title">{!! $lifetimeHeadingHtml !!}</h2>
    <table class="stmt-kpis stmt-kpis--lifetime">
        <tr>
            @foreach ($lifetimeCards as $card)
                <td class="stmt-kpi">
                    <div class="stmt-kpi__label">{!! $card['label'] !!}</div>
                    <div class="stmt-kpi__value">{!! $card['value'] !!}</div>
                </td>
            @endforeach
        </tr>
    </table>

    @if (!empty($fees['groups']))
        <h2 class="section-title">{{ __('Fees breakdown') }}</h2>
        <table class="data-table">
            <thead>
                <tr>
                    @foreach ($feeColumns as $column)
                        <th>{{ $column['label'] }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($fees['groups'] as $group)
        @php
        $cells = $rtlCells([
            ['class' => '', 'html' => e(__($group['label_key']))],
            ['class' => 'amount-col', 'html' => $moneyHtml((float) $group['amount'])],
        ]);
        @endphp
                    <tr>
                        @foreach ($cells as $cell)
                            <td @if ($cell['class'] !== '') class="{{ $cell['class'] }}" @endif>{!! $cell['html'] !!}</td>
                        @endforeach
                    </tr>
                @endforeach
    @php
    $totalFeeCells = $rtlCells([
        ['class' => '', 'html' => '<strong>' . e(__('Total fees')) . '</strong>'],
        ['class' => 'amount-col', 'html' => '<strong>' . $moneyHtml((float) ($fees['total'] ?? 0)) . '</strong>'],
    ]);
    @endphp
                <tr>
                    @foreach ($totalFeeCells as $cell)
                        <td @if ($cell['class'] !== '') class="{{ $cell['class'] }}" @endif>{!! $cell['html'] !!}</td>
                    @endforeach
                </tr>
            </tbody>
        </table>
    @endif

    @if (($cfg['include_txns'] ?? true) && !empty($d['period_transactions']))
        <h2 class="section-title">{{ __('Period transactions') }}</h2>
        <table class="data-table">
            <thead>
                <tr>
                    @foreach ($txnColumns as $column)
                        <th>{{ $column['label'] }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($d['period_transactions'] as $tx)
        @php
        $isCredit = ($tx['type'] ?? '') === 'credit';
        $typeClass = $isCredit ? 'txn-type txn-type--credit' : 'txn-type txn-type--debit';
        $cells = $rtlCells([
            ['class' => 'date', 'html' => e(Str::before((string) ($tx['date'] ?? ''), ' '))],
            ['class' => '', 'html' => e($tx['description'] ?? '')],
            ['class' => '', 'html' => e($txnAccountLabel($tx['account_type'] ?? 'unknown'))],
            ['class' => '', 'html' => '<span class="' . $typeClass . '">' . e(__($isCredit ? 'Credit' : 'Debit')) . '</span>'],
            ['class' => 'amount-col', 'html' => $moneyHtml((float) ($tx['amount'] ?? 0), tone: $isCredit ? 'credit' : 'debit')],
        ]);
        @endphp
                    <tr>
                        @foreach ($cells as $cell)
                            <td @if ($cell['class'] !== '') class="{{ $cell['class'] }}" @endif>{!! $cell['html'] !!}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if (!empty($d['contributions']) || !empty($d['period_installments']))
        <h2 class="section-title">{{ __('This period detail') }}</h2>
        @if (!empty($d['contributions']))
            <div class="stmt-card__title">{{ __('Contributions') }}</div>
            <table class="data-table">
                <thead>
                    <tr>
                        @foreach ($contribColumns as $column)
                            <th>{{ $column['label'] }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($d['contributions'] as $c)
            @php
            if (!empty($c['is_late'])) {
                $notes = e(__('Late'));
                if (($c['late_fee_amount'] ?? 0) > 0) {
                    $notes .= ' · ' . $moneyHtml((float) $c['late_fee_amount']);
                }
            } else {
                $notes = '—';
            }
            $cells = $rtlCells([
                ['class' => '', 'html' => e($c['paid_at'] ?? '—')],
                ['class' => 'amount-col', 'html' => $moneyHtml((float) ($c['amount'] ?? 0))],
                ['class' => '', 'html' => $notes],
            ]);
            @endphp
                        <tr>
                            @foreach ($cells as $cell)
                                <td @if ($cell['class'] !== '') class="{{ $cell['class'] }}" @endif>{!! $cell['html'] !!}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
        @if (!empty($d['period_installments']))
            <div class="stmt-card__title" style="margin-top: 12px;">{{ __('EMI repayments') }}</div>
            <table class="data-table">
                <thead>
                    <tr>
                        @foreach ($emiColumns as $column)
                            <th>{{ $column['label'] }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($d['period_installments'] as $i)
            @php
            $cells = $rtlCells([
                ['class' => '', 'html' => e('#' . ($i['loan_id'] ?? '—'))],
                ['class' => '', 'html' => e((string) ($i['installment_number'] ?? '—'))],
                ['class' => '', 'html' => e($i['due_date'] ?? '—')],
                ['class' => '', 'html' => e($i['paid_at'] ?? '—')],
                ['class' => 'amount-col', 'html' => $moneyHtml((float) ($i['amount'] ?? 0) + (float) ($i['late_fee_amount'] ?? 0))],
            ]);
            @endphp
                        <tr>
                            @foreach ($cells as $cell)
                                <td @if ($cell['class'] !== '') class="{{ $cell['class'] }}" @endif>{!! $cell['html'] !!}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    @endif

    @if (!empty($cfg['footer_disclaimer']))
        <p class="doc-footer muted">{{ $cfg['footer_disclaimer'] }}</p>
    @endif
    @if (!empty($cfg['signature_line']))
        <p class="doc-footer muted">{{ $cfg['signature_line'] }} · {{ $fundName }}</p>
    @endif
</body>
</html>
