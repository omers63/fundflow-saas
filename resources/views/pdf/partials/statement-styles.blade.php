@php
    $isArabic = $isArabic ?? app()->getLocale() === 'ar';
    $accent = $accent ?? '#059669';
    $align = $isArabic ? 'right' : 'left';
@endphp
<style>
    .stmt-body {
        font-size: {{ $isArabic ? '13px' : '10px' }};
        line-height: {{ $isArabic ? '1.35' : '1.25' }};
        padding-bottom: 18px;
    }

    .stmt-body .section-title {
        font-size: {{ $isArabic ? '18px' : '15px' }};
        font-weight: 700;
        margin: 10px 0 6px;
        padding-bottom: 3px;
        text-align: center;
        letter-spacing: 0.02em;
        page-break-after: avoid;
    }

    .stmt-body .section-title__meta {
        display: inline;
        margin: 0 6px;
        font-size: {{ $isArabic ? '14px' : '11px' }};
        font-weight: 600;
        color: #64748b;
        letter-spacing: 0;
        text-transform: none;
    }

    .stmt-body .data-table th,
    .stmt-body .data-table td {
        text-align: center !important;
        padding: 3px 6px;
        line-height: 1.2;
        vertical-align: middle;
    }

    .stmt-body .data-table th {
        font-size: {{ $isArabic ? '13px' : '11px' }};
        font-weight: 700;
        letter-spacing: 0.02em;
        padding: 5px 6px;
    }

    .stmt-body .data-table .amount,
    .stmt-body .stmt-kpi__value .amount {
        margin-left: auto;
        margin-right: auto;
    }

    .stmt-body .data-table td.stmt-progress-cell {
        vertical-align: middle !important;
    }

    .stmt-body .data-table td.stmt-progress-cell .stmt-progress {
        margin-left: auto;
        margin-right: auto;
    }

    .stmt-body .stmt-kpi__value {
        text-align: center;
    }

    .stmt-body .stmt-kpi__value .amount td {
        text-align: center;
    }

    .stmt-hero {
        margin: 0 0 8px;
        padding: 8px 12px;
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
        font-size: {{ $isArabic ? '13px' : '11px' }};
        font-weight: 700;
        letter-spacing: {{ $isArabic ? '0.04em' : '0.08em' }};
        text-transform: uppercase;
        color:
            {{ $accent }}
        ;
        margin-bottom: 5px;
        text-align:
            {{ $align }}
        ;
    }

    .stmt-hero__fund {
        font-size: {{ $isArabic ? '22px' : '20px' }};
        font-weight: 700;
        color: #0f172a;
        line-height: 1.2;
        margin-bottom: 6px;
        text-align:
            {{ $align }}
        ;
    }

    .stmt-hero__meta {
        width: auto;
        border-collapse: collapse;
        margin: 0;
        {{ $isArabic ? 'margin-left: auto; margin-right: 0;' : '' }}
    }

    .stmt-hero__meta td {
        border: 0;
        padding: 2px 0;
        vertical-align: middle;
        color: #334155;
        font-size: {{ $isArabic ? '14px' : '12px' }};
        line-height: 1.35;
    }

    .stmt-hero__meta-label {
        white-space: nowrap;
        font-weight: 700;
        text-align: {{ $align }};
        padding-{{ $isArabic ? 'left' : 'right' }}: 8px;
        width: 1%;
    }

    .stmt-hero__meta-value {
        white-space: nowrap;
        font-weight: 700;
        text-align: {{ $align }};
    }

    .txn-type--credit {
        color: #047857;
        font-weight: 700;
    }

    .txn-type--debit {
        color: #b91c1c;
        font-weight: 700;
    }

    .stmt-kpis {
        width: 100%;
        border-collapse: separate;
        border-spacing: 4px 0;
        margin: 0 0 6px;
        direction: ltr;
    }

    .stmt-kpi {
        width: 25%;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 6px;
        padding: 6px 6px;
        vertical-align: top;
        text-align: center;
    }

    .stmt-kpis--lifetime .stmt-kpi {
        width: 16.66%;
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

    .stmt-kpi-pill {
        display: inline-block;
        background: #e2e8f0;
        color: #334155;
        font-size: 9px;
        font-weight: 700;
        padding: 1px 7px;
        border-radius: 999px;
        vertical-align: middle;
        line-height: 1.3;
        text-align: center;
        text-transform: none;
        letter-spacing: 0;
    }

    .stmt-balance-pill {
        display: inline-block;
        padding: 3px 10px;
        border-radius: 999px;
        vertical-align: middle;
        line-height: 1.2;
        background: #e2e8f0;
        text-align: center;
    }

    .stmt-balance-pill--success {
        background: #047857;
    }

    .stmt-balance-pill--danger {
        background: #b91c1c;
    }

    .stmt-balance-pill--success .amount td.amount-digits,
    .stmt-balance-pill--success .amount td.amount-symbol,
    .stmt-balance-pill--success .currency-code,
    .stmt-balance-pill--danger .amount td.amount-digits,
    .stmt-balance-pill--danger .amount td.amount-symbol,
    .stmt-balance-pill--danger .currency-code {
        color: #ffffff !important;
    }

    .stmt-balance-pill .amount {
        margin-left: auto;
        margin-right: auto;
    }

    .stmt-balance-pill .amount td.amount-digits,
    .stmt-balance-pill .amount td.amount-symbol {
        text-align: center !important;
    }

    .stmt-balance-pill .amount td.amount-digits {
        font-size: 11px;
        line-height: 11px;
    }

    .stmt-kpi__label {
        font-size: {{ $isArabic ? '12px' : '10px' }};
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.03em;
        color: #64748b;
        margin-bottom: 3px;
        text-align: center;
    }

    .stmt-kpi__value {
        font-size: {{ $isArabic ? '14px' : '12px' }};
        font-weight: 700;
        color: #0f172a;
        text-align: center;
    }

    .stmt-two-col {
        width: 100%;
        border-collapse: separate;
        border-spacing: 6px 0;
        margin-bottom: 2px;
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
        border-radius: 6px;
        padding: 6px 8px;
        background: #ffffff;
        text-align:
            {{ $align }}
        ;
    }

    .stmt-card__title {
        font-size: {{ $isArabic ? '14px' : '11px' }};
        font-weight: 700;
        color:
            {{ $accent }}
        ;
        text-transform: uppercase;
        letter-spacing: 0.03em;
        margin-bottom: 4px;
        text-align: center;
    }

    .stmt-meta {
        width: 100%;
        border-collapse: collapse;
        direction: ltr;
    }

    .stmt-meta td {
        border: 0;
        padding: 1px 0;
        vertical-align: middle;
        line-height: 1.2;
    }

    .stmt-meta__label {
        width: 42%;
        color: #64748b;
        font-size: {{ $isArabic ? '12px' : '9px' }};
        font-weight: {{ $isArabic ? '700' : '400' }};
    }

    .stmt-meta__value {
        width: 58%;
        font-weight: 700;
        color: #0f172a;
        font-size: {{ $isArabic ? '13px' : 'inherit' }};
    }

    .stmt-meta--en .stmt-meta__label {
        text-align: left;
        padding-right: 10px !important;
    }

    .stmt-meta--en .stmt-meta__value {
        text-align: left;
    }

    .stmt-meta--ar .stmt-meta__label {
        text-align: right;
        padding-left: 10px !important;
    }

    .stmt-meta--ar .stmt-meta__value {
        text-align: right;
    }

    .stmt-meta--ar .stmt-meta__value .amount,
    .stmt-meta--ar .stmt-meta__value .stmt-balance-pill {
        margin-left: auto !important;
        margin-right: 0 !important;
    }

    .stmt-meta__value .amount {
        vertical-align: middle;
    }

    .stmt-note {
        margin: -2px 0 4px;
        font-size: 8px;
        text-align:
            {{ $align }}
        ;
    }

    .stmt-mini-track {
        display: inline-block;
        width: 72px;
        height: 10px;
        background: #e2e8f0;
        border-radius: 999px;
        overflow: hidden;
        vertical-align: middle;
        line-height: 10px;
    }

    .stmt-mini-track--end {
        direction: rtl;
        text-align: right;
    }

    .stmt-mini-fill {
        display: inline-block;
        height: 10px;
        border-radius: 999px;
        vertical-align: top;
        line-height: 10px;
    }

    .stmt-body .data-table td.stmt-progress-cell {
        vertical-align: middle !important;
        padding-top: 4px;
        padding-bottom: 4px;
    }

    .stmt-progress {
        border-collapse: collapse;
        border-spacing: 0;
        margin: 0 auto;
        direction: ltr;
        vertical-align: middle;
        line-height: 1;
    }

    .stmt-progress td,
    .stmt-progress__cell,
    .data-table .stmt-progress td,
    .data-table .stmt-progress__cell {
        border: 0 !important;
        margin: 0;
        padding: 0 !important;
        background: transparent !important;
        vertical-align: middle !important;
        text-align: center;
        white-space: nowrap;
        line-height: 12px;
    }

    .stmt-mini-pct {
        display: inline-block;
        font-size: 11px;
        font-weight: 700;
        color: #334155;
        vertical-align: middle;
        line-height: 12px;
        padding: 0 4px;
    }

    .stmt-tfoot-label,
    .stmt-tfoot-pill {
        font-size: 8px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.03em;
        margin: 0 auto 4px;
        text-align: center;
    }

    .stmt-tfoot-pill {
        display: inline-block;
        background: #e2e8f0;
        color: #334155;
        padding: 2px 8px;
        border-radius: 999px;
        white-space: nowrap;
        text-align: center;
    }

    .stmt-body .doc-footer {
        text-align: center;
        margin-top: 16px;
        padding-top: 8px;
    }

    .amount-col {
        white-space: nowrap;
        text-align: center;
    }
</style>