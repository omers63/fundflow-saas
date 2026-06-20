@if ($visible ?? false)
    <div
        class="mb-4 rounded-xl border border-amber-200 bg-amber-50/60 px-4 py-3 dark:border-amber-800/40 dark:bg-amber-950/20">
        <p class="mb-2 text-xs font-semibold text-amber-900 dark:text-amber-200">
            {{ __('Statement lines — action required first') }}
        </p>
        <div class="ff-tenant-tab-pills flex flex-wrap gap-2">
            <button type="button" wire:click="setImportsSection('unmatched')" @class([
                'ff-tenant-tab-pills__item',
                'ff-tenant-tab-pills__item--active' => ($importsSection ?? 'unmatched') === 'unmatched',
            ])>
                {{ __('Unmatched') }}
            </button>
            <button type="button" wire:click="setImportsSection('matched')" @class([
                'ff-tenant-tab-pills__item',
                'ff-tenant-tab-pills__item--active' => ($importsSection ?? 'unmatched') === 'matched',
            ])>
                {{ __('Matched / closed') }}
            </button>
        </div>
        @if (($importsSection ?? 'unmatched') === 'unmatched')
            <p class="mt-2 text-[11px] text-amber-800 dark:text-amber-300">
                {{ __('Imported or mirrored rows awaiting post, assignment, or bank match. Use row actions to post to cash or clear.') }}
            </p>
        @else
            <p class="mt-2 text-[11px] text-gray-600 dark:text-gray-400">
                {{ __('Posted, duplicate, or ignored rows — read-only history for audit.') }}
            </p>
        @endif
    </div>
@endif