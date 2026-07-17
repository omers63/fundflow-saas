@php
    $isArabic = $isArabic ?? app()->getLocale() === 'ar';
    $accent = $accent ?? '#059669';
    $align = $isArabic ? 'right' : 'left';
@endphp
<style>
    .stmt-body {
        font-size: 10.5px;
    }

    .stmt-hero {
        margin: 0 0 14px;
        padding: 14px 16px;
        border: 2px solid
            {{ $accent }}
        ;
        border-radius: 10px;
        background: #f8fafc;
    }

    .stmt-hero__table {
        width: 100%;
        border-collapse: collapse;
    }

    .stmt-hero__table td {
        border: 0;
        vertical-align: middle;
        padding: 0;
    }

    .stmt-hero__copy {
        text-align:
            {{ $align }}
        ;
    }

    .stmt-hero__logo {
        width: 64px;
        text-align:
            {{ $isArabic ? 'left' : 'right' }}
        ;
    }

    .stmt-hero__logo img {
        width: 56px;
        height: 56px;
        object-fit: contain;
    }

    .stmt-hero__eyebrow {
        font-size: 9px;
        font-weight: 700;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        color:
            {{ $accent }}
        ;
        margin-bottom: 4px;
        text-align:
            {{ $align }}
        ;
    }

    .stmt-hero__fund {
        font-size: 20px;
        font-weight: 700;
        color: #0f172a;
        line-height: 1.2;
        margin-bottom: 4px;
        text-align:
            {{ $align }}
        ;
    }

    .stmt-hero__period {
        color: #64748b;
        font-size: 11px;
        text-align:
            {{ $align }}
        ;
    }

    .stmt-kpis {
        width: 100%;
        border-collapse: separate;
        border-spacing: 6px 0;
        margin: 0 0 8px;
        direction: ltr;
    }

    .stmt-kpi {
        width: 25%;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        padding: 10px 8px;
        vertical-align: top;
        text-align:
            {{ $align }}
        ;
    }

    .stmt-kpi--accent {
        color: #ffffff;
        border-color: transparent;
    }

    .stmt-kpi--accent .stmt-kpi__label {
        color: rgb(255 255 255 / 0.85);
    }

    .stmt-kpi--accent .stmt-kpi__value,
    .stmt-kpi--accent .amount-digits,
    .stmt-kpi--accent .currency-code {
        color: #ffffff;
    }

    .stmt-kpi__label {
        font-size: 8px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: #64748b;
        margin-bottom: 4px;
        text-align:
            {{ $align }}
        ;
    }

    .stmt-kpi__value {
        font-size: 12px;
        font-weight: 700;
        color: #0f172a;
        text-align:
            {{ $align }}
        ;
    }

    .stmt-two-col {
        width: 100%;
        border-collapse: separate;
        border-spacing: 8px 0;
        margin-bottom: 4px;
        direction: ltr;
    }

    .stmt-two-col>tbody>tr>td {
        width: 50%;
        vertical-align: top;
        padding: 0;
        border: 0;
    }

    .stmt-card {
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        padding: 10px 12px;
        background: #ffffff;
        text-align:
            {{ $align }}
        ;
    }

    .stmt-card__title {
        font-size: 10px;
        font-weight: 700;
        color:
            {{ $accent }}
        ;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        margin-bottom: 8px;
        text-align:
            {{ $align }}
        ;
    }

    .stmt-meta {
        width: 100%;
        border-collapse: collapse;
        direction: ltr;
    }

    .stmt-meta td {
        border: 0;
        padding: 3px 0;
        vertical-align: middle;
        text-align:
            {{ $align }}
        ;
    }

    .stmt-meta__label {
        width: 42%;
        color: #64748b;
        font-size: 9px;
    }

    .stmt-meta__value {
        font-weight: 600;
        color: #0f172a;
    }

    .stmt-meta__value .amount {
        vertical-align: middle;
    }

    .stmt-note {
        margin: -4px 0 8px;
        font-size: 9px;
        text-align:
            {{ $align }}
        ;
    }

    .stmt-year-chart {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 6px;
        direction: ltr;
    }

    .stmt-year-chart td {
        border-bottom: 1px solid #f1f5f9;
        padding: 7px 0;
        vertical-align: middle;
        text-align:
            {{ $align }}
        ;
    }

    .stmt-year-chart__label {
        width: 48px;
        font-weight: 700;
        color: #334155;
        padding-{{ $isArabic ? 'left' : 'right' }}: 8px !important;
        text-align:
            {{ $align }}
        ;
    }

    .stmt-bar-table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 3px;
        direction: ltr;
    }

    .stmt-bar-table td {
        border: 0 !important;
        padding: 1px 0 !important;
        vertical-align: middle;
    }

    .stmt-bar-caption {
        width: 96px;
        font-size: 8px;
        color: #64748b;
        white-space: nowrap;
        text-align:
            {{ $align }}
        ;
        padding-{{ $isArabic ? 'left' : 'right' }}: 6px !important;
    }

    .stmt-bar-track-cell {
        width: auto;
    }

    .stmt-bar-track {
        width: 100%;
        height: 8px;
        background: #e2e8f0;
        border-radius: 999px;
        overflow: hidden;
    }

    .stmt-bar-fill {
        height: 8px;
        border-radius: 999px;
    }

    .stmt-bar-track--end {
        text-align: right;
    }

    .stmt-bar-track--end .stmt-bar-fill {
        float: right;
    }

    .stmt-bar-fill--repay {
        background: #0ea5e9;
    }

    .stmt-bar-amount {
        width: 92px;
        font-size: 9px;
        font-weight: 700;
        white-space: nowrap;
        text-align:
            {{ $isArabic ? 'left' : 'right' }}
        ;
        padding-{{ $isArabic ? 'right' : 'left' }}: 6px !important;
    }

    .stmt-bar-dates {
        font-size: 8px;
        margin-top: 2px;
        text-align:
            {{ $align }}
        ;
    }

    .stmt-inline-totals {
        width: 100%;
        margin: 4px 0 8px;
        border-collapse: collapse;
        direction: ltr;
    }

    .stmt-inline-totals td {
        border: 0;
        padding: 4px 0;
        font-size: 10px;
        color: #334155;
        text-align:
            {{ $align }}
        ;
    }

    .stmt-mini-track {
        display: inline-block;
        width: 54px;
        height: 6px;
        background: #e2e8f0;
        border-radius: 999px;
        overflow: hidden;
        vertical-align: middle;
    }

    .stmt-mini-track--end {
        text-align: right;
    }

    .stmt-mini-track--end .stmt-mini-fill {
        float: right;
    }

    .stmt-mini-fill {
        height: 6px;
        border-radius: 999px;
    }

    .stmt-mini-pct {
        display: inline-block;
        margin-{{ $isArabic ? 'right' : 'left' }}: 4px;
        font-size: 8px;
        font-weight: 700;
        color: #475569;
        vertical-align: middle;
    }

    .amount-col {
        white-space: nowrap;
    }
</style>