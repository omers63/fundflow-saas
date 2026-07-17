@php
    use App\Filament\Support\MoneyDisplay;
    use App\Models\Tenant\Loan;
    use Illuminate\Support\Carbon;
    use Illuminate\Support\Str;

    $d = $statement->details ?? [];
    $m = $d['member_snapshot'] ?? [];
    $currency = $d['currency'] ?? 'USD';
    $accent = $cfg['accent_color'] ?? '#059669';
    $isArabic = app()->getLocale() === 'ar';
    $moneyHtml = fn (float $amount, bool $signed = false): string => MoneyDisplay::pdfHtml($amount, $currency, signed: $signed)?->toHtml() ?? '—';
    $fundName = (string) ($cfg['fund_name'] ?? $cfg['brand'] ?? config('app.name'));
    $loans = $d['loans'] ?? (isset($d['active_loan']) && is_array($d['active_loan']) ? [$d['active_loan']] : []);
    $yearly = $d['yearly_history'] ?? [];
    $months = $d['current_year_months'] ?? [];
    $yearTotals = $d['current_year_totals'] ?? [];
    $lifetime = $d['lifetime'] ?? [];
    $fees = $d['fees'] ?? ['total' => 0, 'groups' => []];
    $maxMonth = max(1.0, (float) ($yearTotals['max_activity'] ?? 1));
    $asOf = $d['as_of'] ?? $statement->generated_at?->toDateString() ?? now()->toDateString();

    $periodParts = explode('-', (string) $statement->period);
    $periodYear = $periodParts[0] ?? '';
    $periodMonth = Carbon::create((int) ($periodParts[0] ?? 2000), (int) ($periodParts[1] ?? 1), 1)
        ->locale(app()->getLocale())
        ->translatedFormat('F');

    $phoneRows = array_filter([
        ['label' => __('Mobile'), 'value' => $m['mobile_phone'] ?? $m['phone'] ?? null],
        ['label' => __('Home'), 'value' => $m['home_phone'] ?? null],
        ['label' => __('Work'), 'value' => $m['work_phone'] ?? null],
    ], fn (array $row): bool => filled($row['value']));

    $monthName = fn (int $month): string => Carbon::create(2000, $month, 1)->translatedFormat('M');

    /** DomPDF does not reverse table columns for dir=rtl — mirror cell order manually. */
    $rtlCells = function (array $cells) use ($isArabic): array {
        return $isArabic ? array_reverse(array_values($cells)) : array_values($cells);
    };

    $kpiCards = [
        [
            'label' => __('Opening balance'),
            'value' => $moneyHtml((float) $statement->opening_balance),
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
            'value' => $moneyHtml((float) $statement->closing_balance),
            'accent' => false,
        ],
    ];
    $kpiCards = $rtlCells($kpiCards);

    $memberMetaRows = [
        ['label' => __('Name'), 'html' => '<strong>'.e($m['name'] ?? '—').'</strong>'],
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
                'html' => '<span dir="ltr">'.e($phone['value']).'</span>',
            ];
        }
    }

    $bankingMetaRows = [
        ['label' => __('IBAN'), 'html' => '<span dir="ltr">'.e($m['iban'] ?? '—').'</span>'],
        ['label' => __('Account number'), 'html' => '<span dir="ltr">'.e($m['bank_account_number'] ?? '—').'</span>'],
        ['label' => __('Monthly contribution'), 'html' => $moneyHtml((float) ($m['monthly_contrib'] ?? 0))],
        ['label' => __('Cash at period end'), 'html' => $moneyHtml((float) ($d['cash_closing'] ?? 0))],
        ['label' => __('Fund at period end'), 'html' => $moneyHtml((float) ($d['fund_closing'] ?? 0))],
        ['label' => __('Generated'), 'html' => e($asOf)],
    ];

    $detailCards = [
        ['title' => __('Member'), 'rows' => $memberMetaRows],
        ['title' => __('Banking & balances'), 'rows' => $bankingMetaRows],
    ];
    $detailCards = $rtlCells($detailCards);

    $yearlyColumns = [
        ['label' => __('Year'), 'key' => 'year'],
        ['label' => __('Contributions'), 'key' => 'contributions'],
        ['label' => __('Repayments'), 'key' => 'repayments'],
        ['label' => __('Total'), 'key' => 'total'],
        ['label' => __('Net'), 'key' => 'net'],
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
            'label' => __('Total lifetime contributions'),
            'value' => $moneyHtml((float) ($lifetime['total_contributions'] ?? 0)),
        ],
        [
            'label' => __('Loans'),
            'value' => e((string) ((int) ($lifetime['loan_count'] ?? 0))).' · '.$moneyHtml((float) ($lifetime['loan_amount'] ?? 0)),
        ],
        [
            'label' => __('Cash balance'),
            'value' => $moneyHtml((float) ($lifetime['cash_balance'] ?? $d['cash_closing'] ?? 0)),
        ],
        [
            'label' => __('Fund balance'),
            'value' => $moneyHtml((float) ($lifetime['fund_balance'] ?? $d['fund_closing'] ?? 0)),
        ],
    ];
    $lifetimeCards = $rtlCells($lifetimeCards);

    $feeColumns = [
        ['label' => __('Fee type'), 'key' => 'type'],
        ['label' => __('Amount'), 'key' => 'amount'],
    ];
    $feeColumns = $rtlCells($feeColumns);

    $txnColumns = [
        ['label' => __('Date'), 'key' => 'date'],
        ['label' => __('Description'), 'key' => 'description'],
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
    ])
    @include('pdf.partials.statement-styles', ['accent' => $accent, 'isArabic' => $isArabic])
</head>
<body class="stmt-body" style="direction: {{ $isArabic ? 'rtl' : 'ltr' }}; text-align: {{ $isArabic ? 'right' : 'left' }};">
    <div class="stmt-hero" style="border-color: {{ $accent }};">
        <table class="stmt-hero__table">
            <tr>
                @if ($isArabic)
                    <td class="stmt-hero__copy">
                        <div class="stmt-hero__eyebrow">{{ __('Monthly Account Statement') }}</div>
                        <div class="stmt-hero__fund">{{ $fundName }}</div>
                        {{-- DomPDF inline LTR: year → month → : → Period so RTL reading is Period → : → month → year. --}}
                        <div class="stmt-hero__period"><span dir="ltr">{{ $periodYear }}</span> {{ $periodMonth }} : {{ __('Period') }}</div>
                    </td>
                    @if (! empty($logoDataUri))
                        <td class="stmt-hero__logo"><img src="{{ $logoDataUri }}" alt=""></td>
                    @endif
                @else
                    @if (! empty($logoDataUri))
                        <td class="stmt-hero__logo"><img src="{{ $logoDataUri }}" alt=""></td>
                    @endif
                    <td class="stmt-hero__copy">
                        <div class="stmt-hero__eyebrow">{{ __('Monthly Account Statement') }}</div>
                        <div class="stmt-hero__fund">{{ $fundName }}</div>
                        <div class="stmt-hero__period">{{ __('Period') }}: {{ $periodMonth }} <span dir="ltr">{{ $periodYear }}</span></div>
                    </td>
                @endif
            </tr>
        </table>
    </div>

    <table class="stmt-kpis">
        <tr>
            @foreach ($kpiCards as $card)
                <td @class(['stmt-kpi', 'stmt-kpi--accent' => $card['accent']]) @if ($card['accent']) style="background: {{ $accent }};" @endif>
                    <div class="stmt-kpi__label">{{ $card['label'] }}</div>
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
                    <table class="stmt-meta">
                        @foreach ($card['rows'] as $row)
                            <tr>
                                @foreach ($rtlCells([
                                    ['class' => 'stmt-meta__label', 'html' => e($row['label'])],
                                    ['class' => 'stmt-meta__value', 'html' => $row['html']],
                                ]) as $cell)
                                    <td class="{{ $cell['class'] }}">{!! $cell['html'] !!}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </table>
                </td>
            @endforeach
        </tr>
    </table>

    @if (! empty($months))
        @php
            $activityYear = (string) ($yearTotals['year'] ?? Str::before($statement->period, '-'));
        @endphp
        <h2 class="section-title">
            @if ($isArabic)
                {{-- DomPDF lays out inline runs LTR; put year first so RTL reading is activity → — → year. --}}
                <span dir="ltr">{{ $activityYear }}</span> — {{ __('Current year activity') }}
            @else
                {{ __('Current year activity') }} — <span dir="ltr">{{ $activityYear }}</span>
            @endif
        </h2>
        <table class="stmt-year-chart">
            @foreach ($months as $row)
                @php
                    $cPct = min(100, (int) round(((float) $row['contributions'] / $maxMonth) * 100));
                    $rPct = min(100, (int) round(((float) $row['repayments'] / $maxMonth) * 100));
                    $cWidth = max($cPct, $row['contributions'] > 0 ? 4 : 0);
                    $rWidth = max($rPct, $row['repayments'] > 0 ? 4 : 0);
                    $trackClass = $isArabic ? 'stmt-bar-track stmt-bar-track--end' : 'stmt-bar-track';
                    $barGroups = [
                        [
                            'caption' => __('Contributions'),
                            'amount' => $moneyHtml((float) $row['contributions']),
                            'fill' => '<div class="'.$trackClass.'"><div class="stmt-bar-fill stmt-bar-fill--contrib" style="width: '.$cWidth.'%; background: '.e($accent).';"></div></div>',
                        ],
                        [
                            'caption' => __('EMI repayments'),
                            'amount' => $moneyHtml((float) $row['repayments']),
                            'fill' => '<div class="'.$trackClass.'"><div class="stmt-bar-fill stmt-bar-fill--repay" style="width: '.$rWidth.'%;"></div></div>',
                        ],
                    ];
                    $yearRowCells = $rtlCells([
                        ['class' => 'stmt-year-chart__label', 'html' => e($monthName((int) $row['month']))],
                        ['class' => 'stmt-year-chart__bars', 'html' => null],
                    ]);
                @endphp
                <tr>
                    @foreach ($yearRowCells as $cell)
                        @if ($cell['class'] === 'stmt-year-chart__bars')
                            <td class="stmt-year-chart__bars">
                                @foreach ($barGroups as $bar)
                                    <table class="stmt-bar-table">
                                        <tr>
                                            @foreach ($rtlCells([
                                                ['class' => 'stmt-bar-caption', 'html' => e($bar['caption'])],
                                                ['class' => 'stmt-bar-track-cell', 'html' => $bar['fill']],
                                                ['class' => 'stmt-bar-amount', 'html' => $bar['amount']],
                                            ]) as $barCell)
                                                <td class="{{ $barCell['class'] }}">{!! $barCell['html'] !!}</td>
                                            @endforeach
                                        </tr>
                                    </table>
                                @endforeach
                                @if (! empty($row['contribution_dates']) || ! empty($row['repayment_dates']))
                                    <div class="stmt-bar-dates muted">
                                        @if (! empty($row['contribution_dates']))
                                            {{ __('Contributed') }}: <span dir="ltr">{{ implode(', ', $row['contribution_dates']) }}</span>
                                        @endif
                                        @if (! empty($row['contribution_dates']) && ! empty($row['repayment_dates'])) · @endif
                                        @if (! empty($row['repayment_dates']))
                                            {{ __('Repaid') }}: <span dir="ltr">{{ implode(', ', $row['repayment_dates']) }}</span>
                                        @endif
                                    </div>
                                @endif
                            </td>
                        @else
                            <td class="{{ $cell['class'] }}">{!! $cell['html'] !!}</td>
                        @endif
                    @endforeach
                </tr>
            @endforeach
        </table>
        <table class="stmt-inline-totals">
            <tr>
                @foreach ($rtlCells([
                    e(__('Year contributions')).': <strong>'.$moneyHtml((float) ($yearTotals['contributions'] ?? 0)).'</strong>',
                    e(__('Year repayments')).': <strong>'.$moneyHtml((float) ($yearTotals['repayments'] ?? 0)).'</strong>',
                ]) as $totalHtml)
                    <td>{!! $totalHtml !!}</td>
                @endforeach
            </tr>
        </table>
    @endif

    @if (! empty($yearly))
        <h2 class="section-title">{{ __('Year-by-year summary') }}</h2>
        <p class="muted stmt-note">{{ __('Since membership year') }} · {{ $m['joined_at'] ?? '—' }}</p>
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
                        $net = $contributions - $repayments;
                        $cells = $rtlCells([
                            ['class' => '', 'html' => e((string) $row['year'])],
                            ['class' => 'amount-col', 'html' => $moneyHtml($contributions)],
                            ['class' => 'amount-col', 'html' => $moneyHtml($repayments)],
                            ['class' => 'amount-col', 'html' => $moneyHtml($total)],
                            ['class' => 'amount-col', 'html' => $moneyHtml($net, signed: true)],
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
                        $progressHtml = '<div class="'.$miniTrackClass.'"><div class="stmt-mini-fill" style="width: '.max(3, $pct).'%; background: '.e($accent).';"></div></div>'
                            .'<span class="stmt-mini-pct" dir="ltr">'.$pct.'%</span>';
                        $cells = $rtlCells([
                            ['class' => '', 'html' => e('#'.($loan['id'] ?? '—'))],
                            ['class' => 'amount-col', 'html' => $moneyHtml((float) ($loan['amount_approved'] ?? 0))],
                            ['class' => 'amount-col', 'html' => $moneyHtml((float) ($loan['emi_amount'] ?? 0))],
                            ['class' => '', 'html' => e($loan['disbursed_at'] ?? '—')],
                            ['class' => '', 'html' => e($statusLabel)],
                            ['class' => '', 'html' => $progressHtml],
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

    <h2 class="section-title">{{ __('Lifetime summary') }}</h2>
    <p class="muted stmt-note">{!! __('Summary as of :date', ['date' => '<span dir="ltr">'.e($asOf).'</span>']) !!}</p>
    <table class="stmt-kpis stmt-kpis--lifetime">
        <tr>
            @foreach ($lifetimeCards as $card)
                <td class="stmt-kpi">
                    <div class="stmt-kpi__label">{{ $card['label'] }}</div>
                    <div class="stmt-kpi__value">{!! $card['value'] !!}</div>
                </td>
            @endforeach
        </tr>
    </table>

    @if (! empty($fees['groups']))
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
                        ['class' => '', 'html' => '<strong>'.e(__('Total fees')).'</strong>'],
                        ['class' => 'amount-col', 'html' => '<strong>'.$moneyHtml((float) ($fees['total'] ?? 0)).'</strong>'],
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

    @if (($cfg['include_txns'] ?? true) && ! empty($d['period_transactions']))
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
                        $cells = $rtlCells([
                            ['class' => 'date', 'html' => e(Str::before((string) ($tx['date'] ?? ''), ' '))],
                            ['class' => '', 'html' => e($tx['description'] ?? '')],
                            ['class' => '', 'html' => e(__($tx['type'] === 'credit' ? 'Credit' : 'Debit'))],
                            ['class' => 'amount-col', 'html' => $moneyHtml((float) ($tx['amount'] ?? 0))],
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

    @if (! empty($d['contributions']) || ! empty($d['period_installments']))
        <h2 class="section-title">{{ __('This period detail') }}</h2>
        @if (! empty($d['contributions']))
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
                            if (! empty($c['is_late'])) {
                                $notes = e(__('Late'));
                                if (($c['late_fee_amount'] ?? 0) > 0) {
                                    $notes .= ' · '.$moneyHtml((float) $c['late_fee_amount']);
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
        @if (! empty($d['period_installments']))
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
                                ['class' => '', 'html' => e('#'.($i['loan_id'] ?? '—'))],
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

    @if (! empty($cfg['footer_disclaimer']))
        <p class="doc-footer muted">{{ $cfg['footer_disclaimer'] }}</p>
    @endif
    @if (! empty($cfg['signature_line']))
        <p class="doc-footer muted">{{ $cfg['signature_line'] }} · {{ $fundName }}</p>
    @endif
</body>
</html>
