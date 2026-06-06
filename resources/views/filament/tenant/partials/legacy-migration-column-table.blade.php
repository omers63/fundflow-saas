<div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
    <table class="min-w-full divide-y divide-gray-200 text-xs dark:divide-white/10">
        <thead class="bg-gray-50 dark:bg-white/5">
            <tr>
                <th class="px-3 py-2 text-start font-semibold">{{ __('Column') }}</th>
                <th class="px-3 py-2 text-start font-semibold">{{ __('Required') }}</th>
                <th class="px-3 py-2 text-start font-semibold">{{ __('Notes') }}</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100 dark:divide-white/10">
            @foreach ($columns as [$col, $required, $hint])
                <tr>
                    <td class="px-3 py-2 font-mono text-gray-900 dark:text-gray-100">{{ $col }}</td>
                    <td class="px-3 py-2">{{ $required ? __('Yes') : __('No') }}</td>
                    <td class="px-3 py-2 text-gray-600 dark:text-gray-400">{{ $hint }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>