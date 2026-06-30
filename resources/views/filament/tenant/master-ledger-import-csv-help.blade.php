@php
use App\Support\MasterReserveLedgerDirection;

$isReserve = isset($account) && MasterReserveLedgerDirection::isReserveLedger($account);
$typeHint = isset($account)
    ? MasterReserveLedgerDirection::importTypeHint($account)
    : __('credit or debit');
$intro = $isReserve
    ? ($account->type === 'invest'
        ? __('Upload credit and debit movements for this master invest ledger. Credits post as invest returns; debits post as invest out placements. Re-importing an exported file skips rows that already exist. Only return and disbursement lines are exported — internal fund transfer legs are omitted.')
        : __('Upload credit and debit movements for this master reserve ledger. Each row runs the same workflow as the ledger actions (fund/disburse, fee deduct/disburse). Rows with system references are not updated — only new movements are posted.'))
    : __('Upload manual ledger credits and debits for this master account. Rows with system references are not updated — only new manual entries are posted.');
@endphp

<div class="space-y-3 text-sm text-gray-600 dark:text-gray-300">
    <p>{{ $intro }}</p>

    <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">
        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-start">
            <thead class="bg-gray-50 dark:bg-gray-900/40">
                <tr>
                    <th class="px-3 py-2 font-medium text-gray-900 dark:text-gray-100">{{ __('Column') }}</th>
                    <th class="px-3 py-2 font-medium text-gray-900 dark:text-gray-100">{{ __('Required') }}</th>
                    <th class="px-3 py-2 font-medium text-gray-900 dark:text-gray-100">{{ __('Notes') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                @foreach ([
    'transacted_at' => [true, __('YYYY-MM-DD or YYYY-MM-DD HH:MM:SS. Aliases: transaction_date, date')],
    'type' => [true, $typeHint],
    'amount' => [true, __('Positive number')],
    'description' => [true, __('Audit trail text')],
    'member_number' => [
        false,
        $isReserve && ($account->type ?? null) === 'fees'
        ? __('Required for credit (fee deduction); optional otherwise')
        : __('Optional member tag for reporting')
    ],
] as $col => [$required, $hint])
                            <tr>
                                <td class="px-3 py-2 font-mono text-gray-900 dark:text-gray-100">{{ $col }}</td>
                                <td class="px-3 py-2">{{ $required ? __('Yes') : __('No') }}</td>
                                <td class="px-3 py-2">{{ $hint }}</td>
                            </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
