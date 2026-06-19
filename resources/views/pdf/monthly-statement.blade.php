@php
    use App\Filament\Support\MoneyDisplay;

    $d = $statement->details ?? [];
    $m = $d['member_snapshot'] ?? [];
    $currency = $d['currency'] ?? 'USD';
    $accent = $cfg['accent_color'] ?? '#0284c7';
    $isArabic = app()->getLocale() === 'ar';
    $moneyHtml = fn (float $amount): string => MoneyDisplay::pdfHtml($amount, $currency)?->toHtml() ?? '—';

    $summaryRows = [
        ['label' => __('Opening balance'), 'html' => $moneyHtml((float) $statement->opening_balance)],
        ['label' => __('Contributions'), 'html' => $moneyHtml((float) $statement->total_contributions)],
        ['label' => __('Loan repayments'), 'html' => $moneyHtml((float) $statement->total_repayments)],
        ['label' => __('Closing balance'), 'html' => $moneyHtml((float) $statement->closing_balance)],
        ['label' => __('Cash at period end'), 'html' => $moneyHtml((float) ($d['cash_closing'] ?? 0))],
        ['label' => __('Fund at period end'), 'html' => $moneyHtml((float) ($d['fund_closing'] ?? 0))],
    ];

    $txnColumns = [
        ['label' => __('Date'), 'key' => 'date'],
        ['label' => __('Description'), 'key' => 'description'],
        ['label' => __('Type'), 'key' => 'type'],
        ['label' => __('Amount'), 'key' => 'amount'],
    ];

    if ($isArabic) {
        $txnColumns = array_reverse($txnColumns);
    }
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ $isArabic ? 'rtl' : 'ltr' }}">

<head>
    <meta charset="UTF-8">
    <title><span>{{ $cfg['brand'] ?? config('app.name') }}</span> — {{ $statement->period_formatted }}</title>
    @include('pdf.partials.layout-styles', ['accent' => $accent, 'isArabic' => $isArabic, 'logoDataUri' => $logoDataUri ?? null])
</head>

<body>
    @include('pdf.partials.document-header', [
        'brand' => $cfg['brand'] ?? config('app.name'),
        'subtitle' => $cfg['tagline'] ?? __('Monthly statement'),
        'meta' => $statement->period_formatted,
        'logoDataUri' => $logoDataUri ?? null,
        'isArabic' => $isArabic,
    ])

    <h2 class="section-title">{{ __('Monthly statement') }} — {{ $statement->period_formatted }}</h2>
    <p class="member-line"><strong>{{ $m['name'] ?? '—' }}</strong> · {{ $m['member_number'] ?? '' }}</p>

    <h2 class="section-title">{{ __('Summary') }}</h2>
    @include('pdf.partials.summary-grid', ['rows' => $summaryRows, 'isArabic' => $isArabic])

    @if (($cfg['include_txns'] ?? true) && ! empty($d['period_transactions']))
        <h2 class="section-title">{{ __('Transactions') }}</h2>
        <table class="data-table" dir="{{ $isArabic ? 'rtl' : 'ltr' }}">
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
                        $cells = [
                            ['class' => 'date', 'html' => e(\Illuminate\Support\Str::before($tx['date'] ?? '', ' '))],
                            ['class' => '', 'html' => e($tx['description'] ?? '')],
                            ['class' => '', 'html' => e($tx['type'] ?? '')],
                            ['class' => 'amount-col', 'html' => $moneyHtml((float) ($tx['amount'] ?? 0))],
                        ];

                        if ($isArabic) {
                            $cells = array_reverse($cells);
                        }
                    @endphp
                    <tr>
                        @foreach ($cells as $cell)
                            <td class="{{ $cell['class'] }}">{!! $cell['html'] !!}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if (($cfg['include_loan'] ?? true) && ! empty($d['active_loan']))
        @php $loan = $d['active_loan']; @endphp
        <h2 class="section-title">{{ __('Active loan') }}</h2>
        <p class="member-line">{{ __('Loan #') }}<span class="amount">#{{ $loan['id'] ?? '—' }}</span> · {{ __(ucfirst($loan['status'] ?? '')) }}</p>
    @endif

    @if (! empty($cfg['footer_disclaimer']))
        <p class="doc-footer muted">{{ $cfg['footer_disclaimer'] }}</p>
    @endif
    @if (! empty($cfg['signature_line']))
        <p class="doc-footer muted">{{ $cfg['signature_line'] }}</p>
    @endif
</body>

</html>
