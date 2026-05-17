@php
    $d = $statement->details ?? [];
    $m = $d['member_snapshot'] ?? [];
    $currency = $d['currency'] ?? 'USD';
    $accent = $cfg['accent_color'] ?? '#0284c7';
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="UTF-8">
    <title>{{ $cfg['brand'] ?? config('app.name') }} — {{ $statement->period_formatted }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1e293b; margin: 24px; }
        h1 { font-size: 18px; margin: 0 0 4px; color: {{ $accent }}; }
        h2 { font-size: 13px; margin: 20px 0 8px; border-bottom: 2px solid {{ $accent }}; padding-bottom: 4px; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { border: 1px solid #e2e8f0; padding: 6px 8px; text-align: {{ app()->getLocale() === 'ar' ? 'right' : 'left' }}; }
        th { background: #f8fafc; font-size: 10px; text-transform: uppercase; }
        .summary td:last-child { text-align: {{ app()->getLocale() === 'ar' ? 'left' : 'right' }}; font-weight: 600; }
        .muted { color: #64748b; font-size: 10px; margin-top: 24px; }
    </style>
</head>
<body>
    <h1>{{ $cfg['brand'] ?? config('app.name') }}</h1>
    <p class="muted">{{ $cfg['tagline'] ?? '' }}</p>

    <h2>{{ __('Monthly statement') }} — {{ $statement->period_formatted }}</h2>
    <p><strong>{{ $m['name'] ?? '—' }}</strong> · {{ $m['member_number'] ?? '' }}</p>

    <table class="summary">
        <tr><td>{{ __('Opening balance') }}</td><td>{{ number_format((float) $statement->opening_balance, 2) }} {{ $currency }}</td></tr>
        <tr><td>{{ __('Contributions') }}</td><td>{{ number_format((float) $statement->total_contributions, 2) }} {{ $currency }}</td></tr>
        <tr><td>{{ __('Loan repayments') }}</td><td>{{ number_format((float) $statement->total_repayments, 2) }} {{ $currency }}</td></tr>
        <tr><td>{{ __('Closing balance') }}</td><td>{{ number_format((float) $statement->closing_balance, 2) }} {{ $currency }}</td></tr>
        <tr><td>{{ __('Cash at period end') }}</td><td>{{ number_format((float) ($d['cash_closing'] ?? 0), 2) }} {{ $currency }}</td></tr>
        <tr><td>{{ __('Fund at period end') }}</td><td>{{ number_format((float) ($d['fund_closing'] ?? 0), 2) }} {{ $currency }}</td></tr>
    </table>

    @if (($cfg['include_txns'] ?? true) && ! empty($d['period_transactions']))
        <h2>{{ __('Transactions') }}</h2>
        <table>
            <thead>
                <tr>
                    <th>{{ __('Date') }}</th>
                    <th>{{ __('Description') }}</th>
                    <th>{{ __('Type') }}</th>
                    <th>{{ __('Amount') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($d['period_transactions'] as $tx)
                    <tr>
                        <td>{{ \Illuminate\Support\Str::before($tx['date'] ?? '', ' ') }}</td>
                        <td>{{ $tx['description'] ?? '' }}</td>
                        <td>{{ $tx['type'] ?? '' }}</td>
                        <td>{{ number_format((float) ($tx['amount'] ?? 0), 2) }} {{ $currency }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if (($cfg['include_loan'] ?? true) && ! empty($d['active_loan']))
        @php $loan = $d['active_loan']; @endphp
        <h2>{{ __('Active loan') }}</h2>
        <p>{{ __('Loan #:id', ['id' => $loan['id'] ?? '—']) }} · {{ ucfirst($loan['status'] ?? '') }}</p>
    @endif

    <p class="muted">{{ $cfg['footer_disclaimer'] ?? '' }}</p>
    <p class="muted">{{ $cfg['signature_line'] ?? '' }}</p>
</body>
</html>
