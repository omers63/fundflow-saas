<?php

declare(strict_types=1);

/**
 * Operational rows to seed before importing storage/samples/bank-clearing-demo.csv.
 *
 * Run: php artisan bank-clearing:seed-demo --tenants=<tenant_id>
 * Then import the CSV from Bank clearing → Import.
 *
 * @return array{
 *     members: list<array{code: string, name: string, number: string}>,
 *     operations: list<array{
 *         reference: string,
 *         type: 'deposit'|'cash_out'|'expense',
 *         amount: float,
 *         date: string,
 *         member_code?: string,
 *         description?: string,
 *         group?: string,
 *     }>
 * }
 */
return [
    'members' => [
        ['code' => 'DEMO-A', 'name' => 'Demo Member Ahmed', 'number' => 'DEMO-BC-01'],
        ['code' => 'DEMO-B', 'name' => 'Demo Member Sara', 'number' => 'DEMO-BC-02'],
        ['code' => 'DEMO-C', 'name' => 'Demo Member Khalid', 'number' => 'DEMO-BC-03'],
        ['code' => 'DEMO-D', 'name' => 'Demo Member Layla', 'number' => 'DEMO-BC-04'],
        ['code' => 'DEMO-E', 'name' => 'Demo Member Omar', 'number' => 'DEMO-BC-05'],
        ['code' => 'DEMO-F', 'name' => 'Demo Member Noor', 'number' => 'DEMO-BC-06'],
        ['code' => 'DEMO-G', 'name' => 'Demo Member Hana', 'number' => 'DEMO-BC-07'],
        ['code' => 'DEMO-H', 'name' => 'Demo Member Youssef', 'number' => 'DEMO-BC-08'],
    ],
    'operations' => [
        // 1:1 auto-match (unique amount + date)
        ['reference' => 'BC-AUTO-01', 'type' => 'deposit', 'amount' => 2500, 'date' => '2026-06-01', 'member_code' => 'DEMO-A'],
        ['reference' => 'BC-AUTO-02', 'type' => 'deposit', 'amount' => 1800, 'date' => '2026-06-02', 'member_code' => 'DEMO-B'],
        ['reference' => 'BC-AUTO-03', 'type' => 'cash_out', 'amount' => 450, 'date' => '2026-06-03', 'member_code' => 'DEMO-C'],
        ['reference' => 'BC-AUTO-04', 'type' => 'expense', 'amount' => 320, 'date' => '2026-06-04'],
        ['reference' => 'BC-AUTO-05', 'type' => 'deposit', 'amount' => 990, 'date' => '2026-06-05', 'member_code' => 'DEMO-D'],
        ['reference' => 'BC-AUTO-06', 'type' => 'cash_out', 'amount' => 175, 'date' => '2026-06-06', 'member_code' => 'DEMO-E'],

        // Ambiguous auto-match (two ops same amount/date — pick manually)
        ['reference' => 'BC-AMB-01A', 'type' => 'deposit', 'amount' => 1200, 'date' => '2026-06-07', 'member_code' => 'DEMO-A', 'description' => 'Ambiguous deposit A'],
        ['reference' => 'BC-AMB-01B', 'type' => 'deposit', 'amount' => 1200, 'date' => '2026-06-07', 'member_code' => 'DEMO-B', 'description' => 'Ambiguous deposit B'],
        ['reference' => 'BC-AMB-02A', 'type' => 'cash_out', 'amount' => 600, 'date' => '2026-06-08', 'member_code' => 'DEMO-C', 'description' => 'Ambiguous cash-out A'],
        ['reference' => 'BC-AMB-02B', 'type' => 'cash_out', 'amount' => 600, 'date' => '2026-06-08', 'member_code' => 'DEMO-D', 'description' => 'Ambiguous cash-out B'],

        // 1→N group: one deposit 5000 = 2000+1500+1500 on bank file
        ['reference' => 'BC-1M-01', 'type' => 'deposit', 'amount' => 5000, 'date' => '2026-06-10', 'member_code' => 'DEMO-F', 'group' => '1M-deposit'],

        // N→1 group: two deposits 2100+1400 = 3500 on bank file
        ['reference' => 'BC-M1-01A', 'type' => 'deposit', 'amount' => 2100, 'date' => '2026-06-11', 'member_code' => 'DEMO-G', 'group' => 'M1-deposit'],
        ['reference' => 'BC-M1-01B', 'type' => 'deposit', 'amount' => 1400, 'date' => '2026-06-11', 'member_code' => 'DEMO-H', 'group' => 'M1-deposit'],

        // 1→N group: one cash-out 1500 = 800+700 on bank file
        ['reference' => 'BC-1M-02', 'type' => 'cash_out', 'amount' => 1500, 'date' => '2026-06-12', 'member_code' => 'DEMO-F', 'group' => '1M-cashout'],

        // N→1 group: two cash-outs 400+500 = 900 on bank file
        ['reference' => 'BC-M1-02A', 'type' => 'cash_out', 'amount' => 400, 'date' => '2026-06-13', 'member_code' => 'DEMO-G', 'group' => 'M1-cashout'],
        ['reference' => 'BC-M1-02B', 'type' => 'cash_out', 'amount' => 500, 'date' => '2026-06-13', 'member_code' => 'DEMO-H', 'group' => 'M1-cashout'],

        // Date window: op 3 days before bank line (manual / widen auto-match days)
        ['reference' => 'BC-DATE-01', 'type' => 'deposit', 'amount' => 775, 'date' => '2026-06-17', 'member_code' => 'DEMO-A'],

        // Date window: op 1 day before bank line (auto-match within ±3 days)
        ['reference' => 'BC-DATE-02', 'type' => 'deposit', 'amount' => 665, 'date' => '2026-06-20', 'member_code' => 'DEMO-B'],

        // Manual match only (duplicate amount on bank file)
        ['reference' => 'BC-MAN-01', 'type' => 'deposit', 'amount' => 1440, 'date' => '2026-06-28', 'member_code' => 'DEMO-C', 'description' => 'Manual match deposit'],
    ],
];
