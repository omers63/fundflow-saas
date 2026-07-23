<x-filament-panels::page>
    <section
        class="rounded-2xl border border-gray-200 bg-white px-5 py-5 shadow-sm dark:border-white/10 dark:bg-gray-900/60">
        <header class="mb-4 border-b border-gray-100 pb-4 dark:border-white/10">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('Communications') }}</h2>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                {{ $this->getSubheading() }}
            </p>
        </header>

        @include('filament.tenant.partials.communications-tab-pills', ['activeTab' => $this->sideTab])

        <div class="min-w-0 space-y-6" wire:key="communications-{{ $this->sideTab }}">
            @if ($this->sideTab === 'inbox')
                <div
                    class="overflow-hidden rounded-xl border border-sky-200 bg-white px-4 py-3 text-sm text-gray-700 shadow-sm dark:border-sky-800/40 dark:bg-slate-800 dark:text-gray-300">
                    <p class="font-semibold text-gray-900 dark:text-white">{{ __('Member conversations') }}</p>
                    <p class="mt-1 text-xs leading-relaxed">
                        {{ __('Communicate with members individually or in bulk. Opening a conversation marks their messages to you as read.') }}
                    </p>
                </div>

                {{ $this->table }}
            @elseif ($this->sideTab === 'templates')
                        <div class="grid gap-6 lg:grid-cols-[220px_minmax(0,1fr)]">
                            <aside class="space-y-4">
                                @foreach ($this->templateOptionGroups() as $groupLabel => $options)
                                    @if ($options !== [])
                                        <div class="space-y-1">
                                            <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">
                                                {{ $groupLabel }}
                                            </p>
                                            @foreach ($options as $key => $label)
                                                <button type="button" wire:click="selectTemplate('{{ $key }}')" @class([
                                                    'block w-full rounded-lg px-3 py-2 text-start text-sm',
                                                    'bg-primary-50 text-primary-700 dark:bg-primary-500/20 dark:text-primary-200' => $this->selectedTemplateKey === $key,
                                                    'text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-white/5' => $this->selectedTemplateKey !== $key,
                                                ])>
                                                    {{ $label }}
                                                </button>
                                            @endforeach
                                        </div>
                                    @endif
                                @endforeach
                            </aside>

                            <div class="space-y-4">
                                <div class="ff-tenant-tab-pills flex flex-wrap gap-2">
                                    @foreach ($this->channelFamilyOptions() as $familyKey => $familyLabel)
                                        <button type="button" wire:click="selectChannelFamily('{{ $familyKey }}')" @class([
                    'ff-tenant-tab-pills__item',
                    'ff-tenant-tab-pills__item--active' => $this->selectedChannelFamily === $familyKey,
                ])>
                                            <x-ff-tab-pill-label :label="$familyLabel" :key="$familyKey" />
                                        </button>
                                    @endforeach
                                </div>

                                <p class="text-xs text-gray-500">{{ $this->channelFamilyHelperText() }}</p>

                                <div class="flex flex-wrap items-center gap-2">
                                    <x-filament::button wire:click="saveTemplate" color="primary" size="sm">
                                        {{ __('Save template') }}
                                    </x-filament::button>
                                    <x-filament::button wire:click="restoreTemplateDefaults" color="gray" size="sm">
                                        {{ __('Restore defaults') }}
                                    </x-filament::button>
                                    <x-filament::button wire:click="setPreviewLocale('en')" color="gray" size="sm" outlined>
                                        EN
                                    </x-filament::button>
                                    <x-filament::button wire:click="setPreviewLocale('ar')" color="gray" size="sm" outlined>
                                        AR
                                    </x-filament::button>
                                </div>

                                <p class="text-xs text-gray-500">
                                    {{ __('Variables: :vars', ['vars' => $this->selectedTemplateVariables()]) }}
                                </p>

                                <div class="grid gap-4 md:grid-cols-2">
                                    <div class="space-y-3">
                                        <h3 class="text-sm font-semibold">{{ __('English') }}</h3>
                                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-300">
                                            {{ $this->channelFamilySubjectLabel() }}
                                            <input type="text" wire:model.live="en_subject"
                                                class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5" />
                                        </label>
                                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-300">
                                            {{ $this->channelFamilyBodyLabel() }}
                                            <textarea wire:model.live="en_body" rows="8"
                                                class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5"></textarea>
                                        </label>
                                    </div>
                                    <div class="space-y-3">
                                        <h3 class="text-sm font-semibold">{{ __('Arabic') }}</h3>
                                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-300">
                                            {{ $this->channelFamilySubjectLabel() }}
                                            <input type="text" wire:model.live="ar_subject"
                                                class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5" />
                                        </label>
                                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-300">
                                            {{ $this->channelFamilyBodyLabel() }}
                                            <textarea wire:model.live="ar_body" rows="8"
                                                class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5"></textarea>
                                        </label>
                                    </div>
                                </div>

                                @if ($this->selectedChannelFamily === 'email')
                                    <div class="grid gap-3 rounded-xl border border-gray-100 p-4 dark:border-white/10 md:grid-cols-2">
                                        <h3 class="md:col-span-2 text-sm font-semibold">{{ __('Brand layout') }}</h3>
                                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-300">
                                            {{ __('From name') }}
                                            <input type="text" wire:model="brand_from_name"
                                                class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5" />
                                        </label>
                                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-300">
                                            {{ __('Primary color') }}
                                            <input type="color" wire:model="brand_primary_color"
                                                class="mt-1 h-10 w-full rounded-lg border-gray-300 dark:border-white/10" />
                                        </label>
                                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-300">
                                            {{ __('Footer (English)') }}
                                            <textarea wire:model="brand_footer_en" rows="2"
                                                class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5"></textarea>
                                        </label>
                                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-300">
                                            {{ __('Footer (Arabic)') }}
                                            <textarea wire:model="brand_footer_ar" rows="2"
                                                class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5"></textarea>
                                        </label>
                                    </div>
                                @endif

                                <div class="rounded-xl border border-dashed border-gray-200 p-4 dark:border-white/10">
                                    <h3 class="mb-2 text-sm font-semibold">
                                        {{ __('Preview (:locale)', ['locale' => strtoupper($this->previewLocale)]) }}
                                        · {{ $this->channelFamilyOptions()[$this->selectedChannelFamily] ?? '' }}
                                    </h3>
                                    <pre
                                        class="whitespace-pre-wrap text-sm text-gray-700 dark:text-gray-200">{{ $this->previewText }}</pre>
                                </div>
                            </div>
                        </div>
            @else
                {{ $this->table }}
            @endif
        </div>
    </section>
</x-filament-panels::page>