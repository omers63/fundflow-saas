@php
    $isArabic = app()->getLocale() === 'ar';

    $summaryRows = [
        ['label' => __('Approved amount'), 'html' => $moneyHtml((float) ($loan->amount_approved ?? $loan->amount_requested))],
        ['label' => __('Outstanding'), 'html' => $moneyHtml($outstanding)],
        ['label' => __('Installments'), 'html' => e($installmentsPaid.' / '.$installmentsTotal)],
    ];

    if ($loan->guarantor) {
        $summaryRows[] = ['label' => __('Guarantor'), 'html' => e($loan->guarantor->name)];
    }

    $scheduleColumns = [
        ['label' => __('#'), 'class' => 'num'],
        ['label' => __('Due'), 'class' => 'date'],
        ['label' => __('Amount'), 'class' => 'amount-col'],
        ['label' => __('Status'), 'class' => 'status'],
        ['label' => __('Collected'), 'class' => 'date'],
    ];

    if ($isArabic) {
        $scheduleColumns = array_reverse($scheduleColumns);
    }
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ $isArabic ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="UTF-8">
    <title><span>{{ $brand }}</span> — {{ __('Loan schedule') }} <span class="amount">#{{ $loan->id }}</span></title>
    @include('pdf.partials.layout-styles', ['accent' => $accent, 'isArabic' => $isArabic, 'logoDataUri' => $logoDataUri])
</head>
<body>
    @include('pdf.partials.document-header', [
        'brand' => $brand,
        'subtitle' => __('Loan repayment schedule'),
        'meta' => __('Loan').' #'.$loan->id,
        'logoDataUri' => $logoDataUri,
        'isArabic' => $isArabic,
    ])

    <p class="member-line">
        <strong>{{ $member->name }}</strong>
        · {{ $member->member_number }}
    </p>

    <h2 class="section-title">{{ __('Summary') }}</h2>
    @include('pdf.partials.summary-grid', ['rows' => $summaryRows, 'isArabic' => $isArabic])

    <h2 class="section-title">{{ __('Schedule') }}</h2>
    <table class="data-table" dir="{{ $isArabic ? 'rtl' : 'ltr' }}">
        <thead>
            <tr>
                @foreach ($scheduleColumns as $column)
                    <th>{{ $column['label'] }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($loan->installments as $installment)
                @php
                    $cells = [
                        ['class' => 'num', 'html' => e((string) $installment->installment_number)],
                        ['class' => 'date', 'html' => e($formatDate($installment->due_date))],
                        ['class' => 'amount-col', 'html' => $moneyHtml((float) $installment->amount)],
                        ['class' => 'status', 'html' => e(__(ucfirst($installment->status)))],
                        ['class' => 'date', 'html' => e($installment->paid_at ? $formatDate($installment->paid_at, 'd M Y H:i') : '—')],
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

    <p class="doc-footer">{{ __('Generated on :date', ['date' => $formatDate(now())]) }}</p>
</body>
</html>
