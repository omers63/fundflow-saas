<?php

declare(strict_types=1);

use App\Services\MemberLoanLifecycleSimulator;
use App\Support\LoanFundExcessDisposition;

beforeEach(function () {
    $this->simulator = app(MemberLoanLifecycleSimulator::class);

    $this->diagramCalc = [
        'min_installment' => 2500.0,
        'member_portion' => 50000.0,
        'master_portion' => 50000.0,
        'settlement_amt' => 10000.0,
        'total_repay' => 60000.0,
        'eligibility_amt' => 24000.0,
        'eligibility_base' => 120000.0,
        'projected_fund' => 50000.0,
        'projection' => ['current_fund' => 50000.0],
        'schedule' => [
            'first_due_date' => '2025-03-05',
            'last_due_date' => '2027-02-05',
        ],
    ];
});

test('diagram example starts active with fund reduced by member portion and a pending schedule', function () {
    $state = $this->simulator->startFromEstimate($this->diagramCalc, 100_000, '2025-01-15');

    expect($state['status'])->toBe(MemberLoanLifecycleSimulator::STATUS_ACTIVE)
        ->and($state['maturity_amount'])->toBe(60000.0)
        ->and($state['top_up'])->toBe(10000.0)
        ->and($state['pre_loan_fund'])->toBe(50000.0)
        ->and($state['fund_balance'])->toBe(-50000.0)
        ->and($state['outstanding_fund_portion'])->toBe(50000.0)
        ->and($state['remaining_months'])->toBe(24)
        ->and($state['full_settlement_amount'])->toBe(100000.0)
        ->and($state['schedule_rows'])->toHaveCount(24)
        ->and($state['schedule_rows'][0]['kind'])->toBe('pending')
        ->and($state['schedule_rows'][0]['due_date'])->toBe('2025-03-05')
        ->and($state['schedule_count'])->toBe(24)
        ->and($state['pending_count'])->toBe(24);
});

test('regular overpay recalculates remaining months and refreshes the schedule', function () {
    $state = $this->simulator->startFromEstimate($this->diagramCalc, 100_000, '2025-01-15');
    $state = $this->simulator->applyRegularPayment($state, 5000.0);

    $pending = array_values(array_filter(
        $state['schedule_rows'],
        fn (array $row): bool => $row['kind'] === 'pending',
    ));

    expect($state['total_repaid'])->toBe(5000.0)
        ->and($state['remaining_maturity'])->toBe(55000.0)
        ->and($state['remaining_months'])->toBe(22)
        ->and($state['outstanding_fund_portion'])->toBe(45000.0)
        ->and($state['fund_balance'])->toBe(-45000.0)
        ->and($state['expected_maturity_date'])->not->toBeNull()
        ->and($pending)->toHaveCount(22)
        ->and($pending[0]['due_date'])->toBe('2025-05-05');
});

test('exact maturity sets fund to top-up and marks schedule paid', function () {
    $state = $this->simulator->startFromEstimate($this->diagramCalc, 100_000, '2025-01-15');
    $state = $this->simulator->applyRegularPayment($state, 60000.0);

    expect($state['status'])->toBe(MemberLoanLifecycleSimulator::STATUS_PAID)
        ->and($state['remaining_maturity'])->toBe(0.0)
        ->and($state['fund_balance'])->toBe(10000.0)
        ->and($state['eligible_for_new_loan'])->toBeFalse()
        ->and($state['pending_count'])->toBe(0);
});

test('overpay past maturity sends surplus to cash and dates paid at last installment', function () {
    $state = $this->simulator->startFromEstimate($this->diagramCalc, 100_000, '2025-01-15');
    $lastDue = collect($state['schedule_rows'])->last()['due_date'];
    $state = $this->simulator->applyRegularPayment($state, 65000.0);

    $payment = collect($state['history'])->firstWhere('type', 'regular_payment');
    $paid = collect($state['history'])->firstWhere('type', 'paid');

    expect($state['status'])->toBe(MemberLoanLifecycleSimulator::STATUS_PAID)
        ->and($state['fund_balance'])->toBe(10000.0)
        ->and($state['cash_balance'])->toBe(5000.0)
        ->and($payment['label'])->toBe(__('Regular payment (:applied to schedule, :cash to cash)', [
            'applied' => number_format(60000.0, 2),
            'cash' => number_format(5000.0, 2),
        ]))
        ->and($payment['at'])->toBe($lastDue)
        ->and($paid['at'])->toBe($lastDue);
});

test('regular payment on a reduced last installment splits surplus to cash', function () {
    $state = $this->simulator->startFromEstimate($this->diagramCalc, 100_000, '2025-01-15');
    // Leave one reduced installment: 24×2500 − 1000 shortfall path via partials totaling 57500
    // remaining 2500 would be full EMI; instead leave 1500 remaining.
    $state = $this->simulator->applyPartialEarlySettlement(
        $state,
        58500.0,
        MemberLoanLifecycleSimulator::PARTIAL_ROLL_UP,
    );

    $pending = collect($state['schedule_rows'])->where('kind', 'pending')->values();
    expect($state['remaining_maturity'])->toBe(1500.0)
        ->and($pending)->toHaveCount(1)
        ->and($pending[0]['amount'])->toBe(1500.0);

    $lastDue = $pending[0]['due_date'];
    $state = $this->simulator->applyRegularPayment($state, 2500.0);

    $payment = collect($state['history'])->firstWhere(
        fn (array $event): bool => ($event['type'] ?? '') === 'regular_payment'
            && str_contains((string) ($event['label'] ?? ''), number_format(1500.0, 2)),
    );
    $paid = collect($state['history'])->firstWhere('type', 'paid');

    expect($state['status'])->toBe(MemberLoanLifecycleSimulator::STATUS_PAID)
        ->and($state['cash_balance'])->toBe(1000.0)
        ->and($payment)->not->toBeNull()
        ->and($payment['label'])->toBe(__('Regular payment (:applied to schedule, :cash to cash)', [
            'applied' => number_format(1500.0, 2),
            'cash' => number_format(1000.0, 2),
        ]))
        ->and($payment['at'])->toBe($lastDue)
        ->and($paid['at'])->toBe($lastDue);
});

test('full early settlement after regular payment restores pre-loan fund', function () {
    $state = $this->simulator->startFromEstimate($this->diagramCalc, 100_000, '2025-01-15');
    $state = $this->simulator->applyRegularPayment($state, 5000.0);
    $required = $state['full_settlement_amount'];
    $nextDue = collect($state['schedule_rows'])->firstWhere('kind', 'pending')['due_date'];
    $state = $this->simulator->applyFullEarlySettlement($state);

    $settled = collect($state['schedule_rows'])->firstWhere('kind', 'cancelled');
    $history = $state['history'][array_key_last($state['history'])];

    expect($required)->toBe(95000.0)
        ->and($state['status'])->toBe(MemberLoanLifecycleSimulator::STATUS_FULLY_SETTLED)
        ->and($state['fund_balance'])->toBe(50000.0)
        ->and($state['eligible_for_new_loan'])->toBeTrue()
        ->and($state['pending_count'])->toBe(0)
        ->and($settled)->not->toBeNull()
        ->and($settled['amount'])->toBe(95000.0)
        ->and($settled['due_date'])->toBe($nextDue)
        ->and($history['type'])->toBe('full_early_settlement')
        ->and($history['amount'])->toBe(95000.0)
        ->and($history['at'])->toBe($nextDue);
});

test('full early settlement rolls the remainder onto the next cycle and drops the pending tail', function () {
    $state = $this->simulator->startFromEstimate($this->diagramCalc, 100_000, '2025-01-15');
    $firstDue = collect($state['schedule_rows'])->firstWhere('kind', 'pending')['due_date'];
    $pendingBefore = collect($state['schedule_rows'])->where('kind', 'pending')->count();
    $required = $state['full_settlement_amount'];

    $state = $this->simulator->applyFullEarlySettlement($state);

    $settled = collect($state['schedule_rows'])->where('kind', 'cancelled')->values();
    $pending = collect($state['schedule_rows'])->where('kind', 'pending');

    expect($required)->toBe(100000.0)
        ->and($pendingBefore)->toBe(24)
        ->and($settled)->toHaveCount(1)
        ->and($settled[0]['amount'])->toBe(100000.0)
        ->and($settled[0]['due_date'])->toBe($firstDue)
        ->and($settled[0]['note'])->toBe(__('Full early settlement'))
        ->and($pending)->toHaveCount(0)
        ->and($state['schedule_count'])->toBe(1)
        ->and($state['history'][array_key_last($state['history'])]['at'])->toBe($firstDue);
});

test('partial roll-up shortens the pending schedule then full settlement can still close', function () {
    $state = $this->simulator->startFromEstimate($this->diagramCalc, 100_000, '2025-01-15');
    $state = $this->simulator->applyPartialEarlySettlement(
        $state,
        5000.0,
        MemberLoanLifecycleSimulator::PARTIAL_ROLL_UP,
    );

    expect($state['status'])->toBe(MemberLoanLifecycleSimulator::STATUS_ACTIVE)
        ->and($state['remaining_months'])->toBe(22)
        ->and($state['pending_count'])->toBe(22);

    $state = $this->simulator->applyFullEarlySettlement($state);

    expect($state['status'])->toBe(MemberLoanLifecycleSimulator::STATUS_FULLY_SETTLED)
        ->and($state['fund_balance'])->toBe(50000.0);
});

test('partial roll-up puts the lump on the first pending cycle and drops covered minus one from the tail', function () {
    $this->diagramCalc['schedule'] = [
        'first_due_date' => '2027-02-05',
        'last_due_date' => '2029-01-05',
        'rows' => array_merge(
            [[
                'kind' => 'grace',
                'due_date' => '2027-01-05',
                'cycle_label' => 'January 2027',
                'amount' => 0.0,
                'number' => null,
            ]],
            array_map(
                fn (int $i): array => [
                    'kind' => 'emi',
                    'due_date' => Carbon\Carbon::parse('2027-02-05')->addMonthsNoOverflow($i)->toDateString(),
                    'cycle_label' => Carbon\Carbon::parse('2027-02-05')->addMonthsNoOverflow($i)->format('F Y'),
                    'amount' => 2500.0,
                    'number' => $i + 1,
                ],
                range(0, 23),
            ),
        ),
    ];

    $state = $this->simulator->startFromEstimate($this->diagramCalc, 100_000, '2027-01-15');
    $lastBefore = collect($state['schedule_rows'])->last();

    expect($state['pending_count'])->toBe(24)
        ->and($state['schedule_count'])->toBe(24)
        ->and($state['schedule_rows'][0]['kind'])->toBe('grace')
        ->and($state['schedule_rows'][1]['due_date'])->toBe('2027-02-05');

    $state = $this->simulator->applyPartialEarlySettlement(
        $state,
        10000.0,
        MemberLoanLifecycleSimulator::PARTIAL_ROLL_UP,
    );

    $rolled = collect($state['schedule_rows'])->firstWhere('kind', 'rolled_up');
    $pending = collect($state['schedule_rows'])->where('kind', 'pending')->values();
    $lastAfter = $pending->last();

    expect($state['status'])->toBe(MemberLoanLifecycleSimulator::STATUS_ACTIVE)
        ->and($rolled)->not->toBeNull()
        ->and($rolled['due_date'])->toBe('2027-02-05')
        ->and($rolled['cycle_label'])->toContain('2027')
        ->and($rolled['amount'])->toBe(10000.0)
        ->and($state['pending_count'])->toBe(20)
        ->and($state['schedule_count'])->toBe(21)
        ->and($state['remaining_months'])->toBe(20)
        ->and($pending[0]['due_date'])->toBe('2027-03-05')
        ->and($lastAfter['due_date'])->not->toBe($lastBefore['due_date'])
        ->and($state['history'][array_key_last($state['history'])]['at'])->toBe('2027-02-05');
});

test('post-paid contributions debit cash and credit fund until eligibility', function () {
    $state = $this->simulator->startFromEstimate($this->diagramCalc, 100_000, '2025-01-15');
    $state = $this->simulator->applyRegularPayment($state, 60000.0);
    $state = $this->simulator->applyCashDeposit($state, 14500.0);

    expect($state['cash_balance'])->toBe(14500.0);

    $state = $this->simulator->applyContribution($state, 500.0);
    $state = $this->simulator->applyContribution($state, 2000.0);

    expect($state['fund_balance'])->toBe(12500.0)
        ->and($state['cash_balance'])->toBe(12000.0)
        ->and($state['eligible_for_new_loan'])->toBeFalse()
        ->and(collect($state['history'])->last()['label'])->toBe(__('Post-loan contribution (:cash from cash)', [
            'cash' => number_format(2000.0, 2),
        ]));

    $state = $this->simulator->applyContribution($state, 12000.0);

    expect($state['fund_balance'])->toBe(24500.0)
        ->and($state['cash_balance'])->toBe(0.0)
        ->and($state['eligible_for_new_loan'])->toBeTrue();
});

test('post-loan contribution requires sufficient simulated cash', function () {
    $state = $this->simulator->startFromEstimate($this->diagramCalc, 100_000, '2025-01-15');
    $state = $this->simulator->applyRegularPayment($state, 60000.0);

    expect(fn () => $this->simulator->applyContribution($state, 500.0))
        ->toThrow(InvalidArgumentException::class, __('Insufficient cash balance.'));
});

test('cash deposit after close increases simulated cash without changing fund', function () {
    $state = $this->simulator->startFromEstimate($this->diagramCalc, 100_000, '2025-01-15');
    $state = $this->simulator->applyRegularPayment($state, 60000.0);
    $fundBefore = (float) $state['fund_balance'];

    $state = $this->simulator->applyCashDeposit($state, 3000.0);
    $deposit = collect($state['history'])->last();

    expect($state['cash_balance'])->toBe(3000.0)
        ->and($state['fund_balance'])->toBe($fundBefore)
        ->and($deposit['type'])->toBe('cash_deposit')
        ->and($deposit['label'])->toBe(__('Cash deposit'))
        ->and($deposit['amount'])->toBe(3000.0)
        ->and($deposit['cash_balance'])->toBe(3000.0)
        ->and($deposit['fund_balance'])->toBe($fundBefore)
        ->and($deposit['at'])->toBe($state['expected_maturity_date']);
});

test('after-close deposit and contribution use selected date and reject dates before maturity', function () {
    $state = $this->simulator->startFromEstimate($this->diagramCalc, 100_000, '2025-01-15');
    $state = $this->simulator->applyRegularPayment($state, 60000.0);
    $maturity = (string) $state['expected_maturity_date'];
    $before = Carbon\Carbon::parse($maturity)->subDay()->toDateString();
    $later = Carbon\Carbon::parse($maturity)->addDays(10)->toDateString();

    expect(fn () => $this->simulator->applyCashDeposit($state, 1000.0, $before))
        ->toThrow(InvalidArgumentException::class, __('Date cannot be before loan maturity (:date).', [
            'date' => $maturity,
        ]));

    $state = $this->simulator->applyCashDeposit($state, 1000.0, $later);
    $deposit = collect($state['history'])->last();
    $state = $this->simulator->applyContribution($state, 500.0, $later);
    $contribution = collect($state['history'])->last();

    expect($deposit['at'])->toBe($later)
        ->and($contribution['at'])->toBe($later)
        ->and($contribution['type'])->toBe('contribution');
});

test('history events record ending cash balance', function () {
    $state = $this->simulator->startFromEstimate($this->diagramCalc, 100_000, '2025-01-15');
    $state = $this->simulator->applyRegularPayment($state, 62500.0);
    $payment = collect($state['history'])->firstWhere('type', 'regular_payment');

    expect($payment['cash_balance'])->toBe(2500.0)
        ->and($payment['fund_balance'])->toBe(10000.0)
        ->and($payment['remaining_maturity'])->toBe(0.0);
});

test('cash-out excess at disbursement moves remaining fund to cash and records history', function () {
    $this->diagramCalc['projected_fund'] = 65000.0;
    $this->diagramCalc['projection'] = ['current_fund' => 65000.0];
    $this->diagramCalc['member_portion'] = 50000.0;
    $this->diagramCalc['excess_fund'] = 15000.0;

    $state = $this->simulator->startFromEstimate(
        $this->diagramCalc,
        100_000,
        '2025-01-15',
        LoanFundExcessDisposition::CASH_OUT,
    );

    $historyTypes = array_column($state['history'], 'type');

    expect($state['fund_balance'])->toBe(-50000.0)
        ->and($state['cash_balance'])->toBe(15000.0)
        ->and($state['excess_to_cash'])->toBe(15000.0)
        ->and($state['cash_out_excess_fund'])->toBeTrue()
        ->and($state['full_settlement_amount'])->toBe(115000.0)
        ->and($historyTypes)->toContain('disbursed')
        ->and($historyTypes)->toContain('excess_to_cash');

    $transfer = collect($state['history'])->firstWhere('type', 'excess_to_cash');

    expect($transfer['amount'])->toBe(15000.0)
        ->and($transfer['label'])->toBe(__('Excess fund transferred to cash'))
        ->and($transfer['cash_balance'])->toBe(15000.0);
});

test('partial roll-up of the full remaining maturity closes as rolled-up not normal maturity paid', function () {
    $state = $this->simulator->startFromEstimate($this->diagramCalc, 100_000, '2025-01-15');
    $firstDue = collect($state['schedule_rows'])->firstWhere('kind', 'pending')['due_date'];
    $remaining = (float) $state['remaining_maturity'];

    $state = $this->simulator->applyPartialEarlySettlement(
        $state,
        $remaining,
        MemberLoanLifecycleSimulator::PARTIAL_ROLL_UP,
    );

    $rolled = collect($state['schedule_rows'])->where('kind', 'rolled_up')->values();
    $paidNotes = collect($state['schedule_rows'])
        ->where('kind', 'paid')
        ->pluck('note')
        ->filter(fn ($note) => $note === __('Paid at normal maturity'));
    $historyTypes = array_column($state['history'], 'type');

    expect($state['status'])->toBe(MemberLoanLifecycleSimulator::STATUS_PAID)
        ->and($state['remaining_maturity'])->toBe(0.0)
        ->and($state['pending_count'])->toBe(0)
        ->and($rolled)->toHaveCount(1)
        ->and($rolled[0]['amount'])->toBe($remaining)
        ->and($rolled[0]['due_date'])->toBe($firstDue)
        ->and($paidNotes)->toBeEmpty()
        ->and($historyTypes)->toContain('partial_roll_up')
        ->and($historyTypes)->not->toContain('paid');
});

test('partial roll-up overpay records schedule and cash split in history', function () {
    $state = $this->simulator->startFromEstimate($this->diagramCalc, 100_000, '2025-01-15');
    $remaining = (float) $state['remaining_maturity'];

    $state = $this->simulator->applyPartialEarlySettlement(
        $state,
        $remaining + 2500.0,
        MemberLoanLifecycleSimulator::PARTIAL_ROLL_UP,
    );

    $event = collect($state['history'])->firstWhere('type', 'partial_roll_up');

    expect($state['status'])->toBe(MemberLoanLifecycleSimulator::STATUS_PAID)
        ->and($state['cash_balance'])->toBe(2500.0)
        ->and($event)->not->toBeNull()
        ->and($event['amount'])->toBe($remaining + 2500.0)
        ->and($event['label'])->toBe(__('Partial roll-up (:applied / :cash cash)', [
            'applied' => number_format($remaining, 2),
            'cash' => number_format(2500.0, 2),
        ]));
});

test('partial settlement below one installment adjusts the next pending cycle without rolling up', function () {
    $state = $this->simulator->startFromEstimate($this->diagramCalc, 100_000, '2025-01-15');
    $beforeFirst = collect($state['schedule_rows'])->firstWhere('kind', 'pending');

    $state = $this->simulator->applyPartialEarlySettlement(
        $state,
        1000.0,
        MemberLoanLifecycleSimulator::PARTIAL_ROLL_UP,
    );

    $pending = collect($state['schedule_rows'])->where('kind', 'pending')->values();
    $rolled = collect($state['schedule_rows'])->where('kind', 'rolled_up');

    expect($state['status'])->toBe(MemberLoanLifecycleSimulator::STATUS_ACTIVE)
        ->and($state['remaining_maturity'])->toBe(59000.0)
        ->and($rolled)->toHaveCount(0)
        ->and($pending)->toHaveCount(24)
        ->and($pending[0]['due_date'])->toBe($beforeFirst['due_date'])
        ->and($pending[0]['amount'])->toBe(1500.0)
        ->and($pending[1]['amount'])->toBe(2500.0)
        ->and($pending->last()['amount'])->toBe(2500.0)
        ->and(round($pending->sum('amount'), 2))->toBe(59000.0);
});

test('regular payment after a reduced next cycle pays full EMI and moves the shortfall forward', function () {
    $state = $this->simulator->startFromEstimate($this->diagramCalc, 100_000, '2025-01-15');
    $firstDue = collect($state['schedule_rows'])->firstWhere('kind', 'pending')['due_date'];
    $secondDue = collect($state['schedule_rows'])->where('kind', 'pending')->values()[1]['due_date'];

    $state = $this->simulator->applyPartialEarlySettlement(
        $state,
        1000.0,
        MemberLoanLifecycleSimulator::PARTIAL_ROLL_UP,
    );
    $state = $this->simulator->applyRegularPayment($state, 2500.0);

    $paid = collect($state['schedule_rows'])->where('kind', 'paid')->values();
    $pending = collect($state['schedule_rows'])->where('kind', 'pending')->values();

    expect($state['remaining_maturity'])->toBe(56500.0)
        ->and($paid)->toHaveCount(1)
        ->and($paid[0]['due_date'])->toBe($firstDue)
        ->and($paid[0]['amount'])->toBe(2500.0)
        ->and($pending)->toHaveCount(23)
        ->and($pending[0]['due_date'])->toBe($secondDue)
        ->and($pending[0]['amount'])->toBe(1500.0)
        ->and($pending[1]['amount'])->toBe(2500.0)
        ->and(round($pending->sum('amount'), 2))->toBe(56500.0);
});

test('partial settlement that zeroes the next installment removes a trailing cycle and updates maturity', function () {
    $state = $this->simulator->startFromEstimate($this->diagramCalc, 100_000, '2025-01-15');
    $originalLastDue = collect($state['schedule_rows'])->last()['due_date'];
    $originalFirstDue = collect($state['schedule_rows'])->firstWhere('kind', 'pending')['due_date'];

    // 1000 three times → remaining 57_000, which needs only 23 EMIs at 2500.
    $state = $this->simulator->applyPartialEarlySettlement($state, 1000.0, MemberLoanLifecycleSimulator::PARTIAL_ROLL_UP);
    $state = $this->simulator->applyPartialEarlySettlement($state, 1000.0, MemberLoanLifecycleSimulator::PARTIAL_ROLL_UP);
    $state = $this->simulator->applyPartialEarlySettlement($state, 1000.0, MemberLoanLifecycleSimulator::PARTIAL_ROLL_UP);

    $pending = collect($state['schedule_rows'])->where('kind', 'pending')->values();

    expect($state['remaining_maturity'])->toBe(57000.0)
        ->and($state['pending_count'])->toBe(23)
        ->and($state['schedule_count'])->toBe(23)
        ->and($state['remaining_months'])->toBe(23)
        ->and($pending)->toHaveCount(23)
        ->and($pending[0]['due_date'])->toBe($originalFirstDue)
        ->and($pending[0]['amount'])->toBe(2000.0)
        ->and($pending->last()['amount'])->toBe(2500.0)
        ->and($pending->last()['due_date'])->not->toBe($originalLastDue)
        ->and($state['expected_maturity_date'])->toBe($pending->last()['due_date'])
        ->and(round($pending->sum('amount'), 2))->toBe(57000.0);
});

test('partial settlement with a remainder rolls up full EMIs and adjusts the next pending cycle', function () {
    $state = $this->simulator->startFromEstimate($this->diagramCalc, 100_000, '2025-01-15');

    $state = $this->simulator->applyPartialEarlySettlement(
        $state,
        3000.0,
        MemberLoanLifecycleSimulator::PARTIAL_ROLL_UP,
    );

    $rolled = collect($state['schedule_rows'])->firstWhere('kind', 'rolled_up');
    $pending = collect($state['schedule_rows'])->where('kind', 'pending')->values();

    expect($rolled)->not->toBeNull()
        ->and($rolled['amount'])->toBe(3000.0)
        ->and($state['pending_count'])->toBe(23)
        ->and($state['remaining_maturity'])->toBe(57000.0)
        ->and($pending[0]['amount'])->toBe(2000.0)
        ->and($pending[1]['amount'])->toBe(2500.0)
        ->and($pending->last()['amount'])->toBe(2500.0)
        ->and(round($pending->sum('amount'), 2))->toBe(57000.0);
});
