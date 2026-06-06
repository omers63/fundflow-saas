<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">

<head>
    <meta charset="utf-8">
    <title>{{ __('Reconciliation snapshot') }} #{{ $snapshot->id }}</title>
    <style>
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 10pt;
            color: #111;
        }

        h1 {
            font-size: 16pt;
            margin: 0 0 8px;
        }

        h2 {
            font-size: 11pt;
            margin: 16px 0 6px;
            border-bottom: 1px solid #ccc;
        }

        .muted {
            color: #555;
            font-size: 9pt;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 6px;
        }

        th,
        td {
            border: 1px solid #ccc;
            padding: 4px 6px;
            text-align: left;
            vertical-align: top;
        }

        th {
            background: #f3f4f6;
            font-size: 9pt;
        }

        .pass {
            color: #047857;
            font-weight: bold;
        }

        .fail {
            color: #b91c1c;
            font-weight: bold;
        }

        .warn {
            color: #b45309;
        }

        pre {
            font-size: 7pt;
            background: #f9fafb;
            padding: 6px;
            overflow: hidden;
            white-space: pre-wrap;
            word-break: break-all;
        }
    </style>
</head>

<body>
    <h1>{{ __('Financial reconciliation report') }}</h1>
    <p class="muted">{{ __('Snapshot') }} #{{ $snapshot->id }} · {{ __('Mode') }} {{ $snapshot->mode }} ·
        {{ __('As of') }} {{ $snapshot->as_of->format('Y-m-d H:i T') }}</p>
    @if ($snapshot->period_start && $snapshot->period_end)
        <p class="muted">{{ __('Period') }} {{ $snapshot->period_start->format('Y-m-d') }} →
            {{ $snapshot->period_end->format('Y-m-d') }}</p>
    @endif

    <h2>{{ __('Verdict') }}</h2>
    <p>
        <strong>{{ __('Result') }}:</strong>
        <span
            class="{{ $snapshot->is_passing ? 'pass' : 'fail' }}">{{ $snapshot->is_passing ? __('PASS') : __('FAIL') }}</span>
        · {{ __('Critical issues') }}: {{ $snapshot->critical_issues }} · {{ __('Warnings') }}:
        {{ $snapshot->warnings }}
    </p>

    @if (!empty($snapshot->report['coverage_matrix']))
        <h2>{{ __('Coverage matrix') }}</h2>
        <p class="muted">{{ __('Which reconciliation checks apply to each flow or posting area.') }}</p>
        <table>
            <thead>
                <tr>
                    <th style="width: 32%;">{{ __('Flow / area') }}</th>
                    <th>{{ __('Checks (severity)') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($snapshot->report['coverage_matrix'] as $row)
                    <tr>
                        <td>{{ $row['flow'] ?? '—' }}</td>
                        <td>
                            @foreach ($row['checks'] ?? [] as $c)
                                @php
                                    $ck = $c['key'] ?? '';
                                    $severity = $c['severity'] ?? '—';
                                    $sevClass = $severity === 'ok'
                                        ? 'pass'
                                        : ($severity === 'critical' ? 'fail' : ($severity === 'warning' ? 'warn' : ''));
                                    $lbl = ($ck !== '' && isset($snapshot->report['checks'][$ck]))
                                        ? ($snapshot->report['checks'][$ck]['label'] ?? $ck)
                                        : $ck;
                                @endphp
                                @if ($sevClass)<span class="{{ $sevClass }}">@endif{{ $lbl }} —
                                    {{ $severity }}@if ($sevClass)</span>@endif@if (!$loop->last)<br>@endif
                            @endforeach
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <h2>{{ __('Check summary') }}</h2>
    <table>
        <thead>
            <tr>
                <th>{{ __('Check') }}</th>
                <th>{{ __('Severity') }}</th>
                <th>{{ __('Notes') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($snapshot->report['checks'] ?? [] as $key => $check)
                <tr>
                    <td>{{ $check['label'] ?? $key }}</td>
                    <td>{{ $check['severity'] ?? '—' }}</td>
                    <td>
                        @if ($key === 'ledger_balances')
                            {{ __('Mismatches') }}: {{ $check['mismatch_count'] ?? 0 }}
                        @elseif ($key === 'global_trial')
                            Δ {{ __('credits−debits') }}: {{ number_format($check['delta'] ?? 0, 2) }}
                        @elseif ($key === 'paired_control_totals')
                            {{ __('Cash') }} Δ {{ number_format($check['cash_delta'] ?? 0, 2) }} · {{ __('Fund') }} Δ
                            {{ number_format($check['fund_delta'] ?? 0, 2) }}
                        @elseif (str_starts_with((string) $key, 'loans_') || str_contains((string) $key, 'loan'))
                            @if (isset($check['mismatch_count']))
                                {{ __('Mismatches') }}: {{ $check['mismatch_count'] }}
                            @else
                                —
                            @endif
                        @elseif ($key === 'contributions_ledger')
                            {{ __('Missing ledger rows') }}: {{ $check['missing_ledger_count'] ?? 0 }} · {{ __('Master fund') }}
                            Δ {{ number_format($check['master_fund_delta'] ?? 0, 2) }}
                        @elseif ($key === 'bank_statement_vs_book')
                            {{ __('Book') }} {{ number_format($check['master_cash_book'] ?? 0, 2) }} {{ __('vs stated') }}
                            {{ isset($check['declared_balance']) ? number_format($check['declared_balance'], 2) : '—' }}
                        @else
                            —
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <h2>{{ __('Pipeline') }}</h2>
    <table>
        <tr>
            <th>{{ __('Bank unposted') }}</th>
            <td>{{ $snapshot->report['pipeline']['bank_unposted_count'] ?? 0 }} {{ __('rows') }}
                ({{ number_format($snapshot->report['pipeline']['bank_unposted_amount'] ?? 0, 2) }} SAR)</td>
        </tr>
        <tr>
            <th>{{ __('SMS unposted') }}</th>
            <td>{{ $snapshot->report['pipeline']['sms_unposted_count'] ?? 0 }} {{ __('rows') }}
                ({{ number_format($snapshot->report['pipeline']['sms_unposted_amount'] ?? 0, 2) }} SAR)</td>
        </tr>
    </table>

    <h2>{{ __('Report payload (truncated)') }}</h2>
    <p class="muted">{{ __('Use the JSON download in the admin UI for the complete machine-readable snapshot.') }}</p>
    <pre>@php
        $json = json_encode(
            $snapshot->report,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
        echo e(\Illuminate\Support\Str::limit($json, 12000, "\n… [truncated]"));
    @endphp</pre>
</body>

</html>