<?php

declare(strict_types=1);

/**
 * Companion to storage/samples/sms-clearing-demo.csv (45 SMS rows).
 *
 * Recommended template (Settings → SMS import templates):
 *   sms_column: message
 *   amount_pattern: /SAR\s*(?P<amount>[\d,]+\.?\d*)/i
 *   date_pattern: /on\s+(?P<date>\d{2}\/\d{2}\/\d{4})/i
 *   date_pattern_format: d/m/Y
 *   reference_pattern: /Ref[:\s]+(?P<reference>[A-Z0-9-]+)/i
 *   member_match_pattern: /Member[:\s]+(?P<member>DEMO-SMS-\d+)/i
 *   member_match_field: member_number
 *   credit_keywords: credited, received, deposit, credit
 *   debit_keywords: debited, paid, purchase, debit, withdraw
 *
 * Seed matching operational rows first (mirror bank demo):
 *   php artisan bank-clearing:seed-demo --tenants=<tenant_id>
 *   (uses storage/samples/bank-clearing-demo-manifest.php — map BC-* refs to SMS-* refs below)
 *
 * Or accept deposits / cash-outs manually for DEMO-SMS-* members, then import this CSV
 * from SMS clearing → Import.
 *
 * @return array{
 *     members: list<array{code: string, name: string, number: string}>,
 *     rows: list<array{reference: string, scenario: string, expects: string}>
 * }
 */
return [
    'members' => [
        ['code' => 'DEMO-A', 'name' => 'Demo SMS Ahmed', 'number' => 'DEMO-SMS-01'],
        ['code' => 'DEMO-B', 'name' => 'Demo SMS Sara', 'number' => 'DEMO-SMS-02'],
        ['code' => 'DEMO-C', 'name' => 'Demo SMS Khalid', 'number' => 'DEMO-SMS-03'],
        ['code' => 'DEMO-D', 'name' => 'Demo SMS Layla', 'number' => 'DEMO-SMS-04'],
        ['code' => 'DEMO-E', 'name' => 'Demo SMS Omar', 'number' => 'DEMO-SMS-05'],
        ['code' => 'DEMO-F', 'name' => 'Demo SMS Noor', 'number' => 'DEMO-SMS-06'],
        ['code' => 'DEMO-G', 'name' => 'Demo SMS Hana', 'number' => 'DEMO-SMS-07'],
        ['code' => 'DEMO-H', 'name' => 'Demo SMS Youssef', 'number' => 'DEMO-SMS-08'],
    ],
    'rows' => [
        ['reference' => 'SMS-AUTO-01', 'scenario' => '1:1 ops match', 'expects' => 'credit · member matched · auto-post · pairs BC-AUTO-01 deposit'],
        ['reference' => 'SMS-AUTO-02', 'scenario' => '1:1 ops match', 'expects' => 'credit · pairs BC-AUTO-02'],
        ['reference' => 'SMS-AUTO-03', 'scenario' => '1:1 cash-out', 'expects' => 'debit · pairs BC-AUTO-03 cash-out'],
        ['reference' => 'SMS-AUTO-04', 'scenario' => 'debit no member', 'expects' => 'purchase keyword · unmatched member · unposted'],
        ['reference' => 'SMS-AUTO-05', 'scenario' => 'deposit keyword', 'expects' => 'credit via deposit keyword · pairs BC-AUTO-05'],
        ['reference' => 'SMS-AUTO-06', 'scenario' => 'withdraw keyword', 'expects' => 'debit via withdraw · pairs BC-AUTO-06'],
        ['reference' => 'SMS-AMB-01A', 'scenario' => 'ambiguous ops', 'expects' => 'same amount/date as 01B · manual ops match'],
        ['reference' => 'SMS-AMB-01B', 'scenario' => 'ambiguous ops', 'expects' => 'same amount/date as 01A · manual ops match'],
        ['reference' => 'SMS-AMB-02A', 'scenario' => 'ambiguous cash-out', 'expects' => 'debit · manual match'],
        ['reference' => 'SMS-AMB-02B', 'scenario' => 'ambiguous cash-out', 'expects' => 'debit · manual match'],
        ['reference' => 'SMS-1M-01A', 'scenario' => '1→N group part 1', 'expects' => 'credit 2000 · group with B+C = 5000 ops'],
        ['reference' => 'SMS-1M-01B', 'scenario' => '1→N group part 2', 'expects' => 'credit 1500'],
        ['reference' => 'SMS-1M-01C', 'scenario' => '1→N group part 3', 'expects' => 'credit 1500'],
        ['reference' => 'SMS-M1-01', 'scenario' => 'N→1 group', 'expects' => 'credit 3500 · one SMS clears two ops rows'],
        ['reference' => 'SMS-1M-02A', 'scenario' => '1→N cash-out part 1', 'expects' => 'debit 800'],
        ['reference' => 'SMS-1M-02B', 'scenario' => '1→N cash-out part 2', 'expects' => 'debit 700'],
        ['reference' => 'SMS-M1-02', 'scenario' => 'N→1 cash-out', 'expects' => 'debit 900'],
        ['reference' => 'SMS-POST-01', 'scenario' => 'deposit keyword', 'expects' => 'credit · no pre-seeded ops · Post as / manual'],
        ['reference' => 'SMS-POST-02', 'scenario' => 'paid keyword', 'expects' => 'debit · no member'],
        ['reference' => 'SMS-POST-03', 'scenario' => 'received keyword', 'expects' => 'credit'],
        ['reference' => 'SMS-IGN-01', 'scenario' => 'bank fee', 'expects' => 'tiny debit · no ops row · ignore/delete'],
        ['reference' => 'SMS-IGN-02', 'scenario' => 'statement fee', 'expects' => 'tiny debit · ignore'],
        ['reference' => 'SMS-DATE-01', 'scenario' => 'date window +3d', 'expects' => 'ops 17/06 · SMS 20/06 · manual if outside tolerance'],
        ['reference' => 'SMS-DATE-02', 'scenario' => 'date window +1d', 'expects' => 'ops 20/06 · SMS 21/06 · auto within ±3d'],
        ['reference' => 'SMS-OPEN-01', 'scenario' => 'misc credit', 'expects' => 'no member · unposted · no ops'],
        ['reference' => 'SMS-OPEN-02', 'scenario' => 'misc credit matched', 'expects' => 'member · auto-post · no ops'],
        ['reference' => 'SMS-OPEN-03', 'scenario' => 'misc debit', 'expects' => 'no member'],
        ['reference' => 'SMS-OPEN-04', 'scenario' => 'misc debit matched', 'expects' => 'member · auto-post'],
        ['reference' => 'SMS-OPEN-05', 'scenario' => 'received keyword', 'expects' => 'credit'],
        ['reference' => 'SMS-OPEN-06', 'scenario' => 'ATM deposit keyword', 'expects' => 'credit · no member'],
        ['reference' => 'SMS-OPEN-07', 'scenario' => 'paid utility', 'expects' => 'debit'],
        ['reference' => 'SMS-OPEN-08', 'scenario' => 'refund credit', 'expects' => 'credit · no member'],
        ['reference' => 'SMS-OPEN-09', 'scenario' => 'transfer in', 'expects' => 'default credit type'],
        ['reference' => 'SMS-OPEN-10', 'scenario' => 'transfer out', 'expects' => 'debit'],
        ['reference' => 'SMS-OPEN-11', 'scenario' => 'credit keyword', 'expects' => 'credit · member'],
        ['reference' => 'SMS-OPEN-12', 'scenario' => 'withdraw petty cash', 'expects' => 'debit · member'],
        ['reference' => 'SMS-MAN-01', 'scenario' => 'manual ops only', 'expects' => 'pairs BC-MAN-01 · duplicate amount with MAN-02'],
        ['reference' => 'SMS-MAN-02', 'scenario' => 'same amount diff ref', 'expects' => 'not a duplicate · ambiguous with MAN-01'],
        ['reference' => 'SMS-DUP-01', 'scenario' => 'duplicate (first)', 'expects' => 'imported · auto-post if member exists'],
        ['reference' => 'SMS-DUP-01', 'scenario' => 'duplicate (second)', 'expects' => 'is_duplicate=true · not posted'],
        ['reference' => 'SMS-UNMATCH-01', 'scenario' => 'no member token', 'expects' => 'member_id null · Unmatched member queue'],
        ['reference' => 'SMS-UNMATCH-02', 'scenario' => 'unknown member', 'expects' => 'member_id null · DEMO-SMS-99 missing'],
        ['reference' => 'SMS-NOAMT-01', 'scenario' => 'unparseable amount', 'expects' => 'amount null · import error row'],
        ['reference' => 'SMS-DEFAULT-01', 'scenario' => 'no credit/debit keyword', 'expects' => 'default_transaction_type credit'],
        ['reference' => 'SMS-COMMA-01', 'scenario' => 'Arabic + comma thousands', 'expects' => 'credit 12345.67 · member DEMO-SMS-08'],
    ],
];
