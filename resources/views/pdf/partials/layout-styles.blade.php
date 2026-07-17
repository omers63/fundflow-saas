@php
$isArabic = $isArabic ?? app()->getLocale() === 'ar';
$accent = $accent ?? '#534ab7';
$logoDataUri = $logoDataUri ?? null;
@endphp
<style>
    @page {
        margin: 28px 32px 36px;
    }

    * {
        box-sizing: border-box;
    }

    html {
        direction: {{ $isArabic ? 'rtl' : 'ltr' }};
    }

    body {
        font-family: {{ $pdfFont ?? 'DejaVu Sans' }}, sans-serif;
        font-size: 11px;
        line-height: 1.45;
        color: #1e293b;
        margin: 0;
        direction: {{ $isArabic ? 'rtl' : 'ltr' }};
        text-align: {{ $isArabic ? 'right' : 'left' }};
    }

    .doc-header {
        width: 100%;
        margin-bottom: 22px;
        padding-bottom: 16px;
        border-bottom: 3px solid
            {{ $accent }}
        ;
    }

    .doc-header__layout {
        width: 100%;
        border-collapse: collapse;
    }

    .doc-header__layout td {
        vertical-align: middle;
        border: 0;
        padding: 0;
    }

    .doc-header__logo-cell {
        width: 72px;
    }

    .doc-header__logo {
        width: 56px;
        height: 56px;
        object-fit: contain;
        display: block;
    }

    .doc-header__brand {
        font-size: 20px;
        font-weight: 700;
        color:
            {{ $accent }}
        ;
        margin: 0 0 4px;
        line-height: 1.2;
    }

    .doc-header__subtitle {
        margin: 0;
        color: #64748b;
        font-size: 11px;
    }

    .doc-header__meta {
        color: #64748b;
        font-size: 10px;
        margin-top: 6px;
    }

    .section-title {
        font-size: 13px;
        font-weight: 700;
        color: {{ $accent }};
        margin: 22px 0 10px;
        padding-bottom: 5px;
        border-bottom: 1px solid #e2e8f0;
        text-align: {{ $isArabic ? 'right' : 'left' }};
    }

    .member-line {
        margin: 0 0 16px;
        font-size: 11px;
    }

    .member-line strong {
        color: #0f172a;
    }

    .summary-grid {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
        margin: 0 0 8px;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        overflow: hidden;
        direction: ltr;
    }

    .summary-grid td {
        padding: 10px 12px;
        border-bottom: 1px solid #e2e8f0;
        vertical-align: middle;
    }

    .summary-grid tr:last-child td {
        border-bottom: 0;
    }

    .summary-grid__label {
        color: #64748b;
        background: #f8fafc;
        font-size: 10px;
        text-transform: uppercase;
        letter-spacing: 0.03em;
    }

    .summary-grid--en .summary-grid__label {
        width: 55%;
        text-align: left;
    }

    .summary-grid--en .summary-grid__value {
        width: 45%;
        font-weight: 700;
        color: #0f172a;
        text-align: right;
    }

    .summary-grid--ar .summary-grid__label {
        width: 45%;
        text-align: right;
    }

    .summary-grid--ar .summary-grid__value {
        width: 55%;
        font-weight: 700;
        color: #0f172a;
        text-align: left;
    }

    .data-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 4px;
        direction: ltr;
    }

    .data-table th,
    .data-table td {
        border: 1px solid #e2e8f0;
        padding: 7px 9px;
        text-align: {{ $isArabic ? 'right' : 'left' }};
        vertical-align: middle;
    }

    .data-table th {
        background:
            {{ $accent }}
        ;
        color: #ffffff;
        font-size: 9px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.04em;
    }

    .data-table tbody tr:nth-child(even) td {
        background: #f8fafc;
    }

    .data-table td.num {
        text-align: center;
        font-weight: 600;
        width: 36px;
    }

    .data-table td.date {
        white-space: nowrap;
    }

    .doc-footer {
        margin-top: 28px;
        padding-top: 12px;
        border-top: 1px solid #e2e8f0;
        color: #94a3b8;
        font-size: 9px;
        text-align: {{ $isArabic ? 'right' : 'left' }};
    }

    .amount {
        display: inline-table;
        border-collapse: collapse;
        border-spacing: 0;
        direction: ltr;
        vertical-align: middle;
        line-height: 1;
    }

    .amount td,
    .stmt-meta .amount td,
    .stmt-kpi .amount td,
    .data-table .amount td,
    .stmt-bar-amount .amount td,
    .stmt-inline-totals .amount td {
        border: 0 !important;
        margin: 0;
        vertical-align: middle !important;
        background: transparent !important;
    }

    .amount td.amount-symbol,
    .stmt-meta .amount td.amount-symbol,
    .stmt-kpi .amount td.amount-symbol,
    .data-table .amount td.amount-symbol {
        padding: 0 6px 0 0 !important;
        line-height: 0 !important;
        width: 1%;
        white-space: nowrap;
    }

    .amount td.amount-digits,
    .stmt-meta .amount td.amount-digits,
    .stmt-kpi .amount td.amount-digits,
    .data-table .amount td.amount-digits {
        padding: 0 !important;
        font-weight: 700;
        font-variant-numeric: tabular-nums;
        font-size: 12px;
        line-height: 12px;
        white-space: nowrap;
    }

    .currency-symbol {
        display: block;
        width: 12px;
        height: 12px;
        margin: 0;
        padding: 0;
        vertical-align: middle;
    }

    .currency-code {
        font-size: 11px;
        font-weight: 700;
        letter-spacing: 0.04em;
        vertical-align: middle;
        line-height: 12px;
    }

    .muted {
        color: #64748b;
    }
</style>