@php
    $pickerGroups = $this->filteredTemplatePickerGroups();
    $channelShort = $this->channelFamilyShortOptions();
    $workspaceClass = $this->templatesEditorFocus
        ? 'ff-templates-workspace ff-templates-workspace--editor-focus'
        : 'ff-templates-workspace';
@endphp

<div class="{{ $workspaceClass }}" wire:key="templates-workspace">
    {{-- Template list / browse pane --}}
    <aside class="ff-templates-list" aria-label="{{ __('Templates') }}">
        <div class="ff-templates-list__toolbar space-y-3">
            <div>
                <label class="sr-only" for="ff-template-search">{{ __('Search templates') }}</label>
                <input
                    id="ff-template-search"
                    type="search"
                    wire:model.live.debounce.300ms="templateSearch"
                    placeholder="{{ __('Search templates…') }}"
                    class="ff-templates-list__search w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5"
                />
            </div>

            <div class="ff-templates-list__filters flex flex-wrap gap-1.5" role="group" aria-label="{{ __('Audience') }}">
                @foreach ([
                    'all' => __('All'),
                    'member' => __('Members'),
                    'admin' => __('Admin'),
                ] as $filterKey => $filterLabel)
                    <button
                        type="button"
                        wire:click="setTemplateAudienceFilter('{{ $filterKey }}')"
                        @class([
                            'ff-templates-list__filter',
                            'ff-templates-list__filter--active' => $this->templateAudienceFilter === $filterKey,
                        ])
                    >
                        {{ $filterLabel }}
                    </button>
                @endforeach
            </div>
        </div>

        <div class="ff-templates-list__groups mt-4 space-y-4">
            @forelse ($pickerGroups as $audience => $group)
                <div class="space-y-1">
                    <p class="ff-templates-list__group-label">{{ $group['label'] }}</p>
                    @foreach ($group['items'] as $item)
                        <button
                            type="button"
                            wire:click="selectTemplate('{{ $item['key'] }}')"
                            @class([
                                'ff-templates-list__item',
                                'ff-templates-list__item--active' => $this->selectedTemplateKey === $item['key'],
                            ])
                        >
                            <span class="ff-templates-list__item-label">{{ $item['label'] }}</span>
                            @if ($item['customized'])
                                <span class="ff-templates-list__item-badge">{{ __('Custom') }}</span>
                            @endif
                        </button>
                    @endforeach
                </div>
            @empty
                <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('No templates match your search.') }}</p>
            @endforelse
        </div>

        <div class="ff-templates-brand mt-6 border-t border-gray-100 pt-4 dark:border-white/10">
            <button
                type="button"
                class="ff-templates-brand__toggle"
                wire:click="toggleBrandPanel"
                aria-expanded="{{ $this->brandPanelOpen ? 'true' : 'false' }}"
            >
                <span>{{ __('Email brand') }}</span>
                <x-heroicon-o-chevron-down @class(['h-4 w-4 transition', 'rotate-180' => $this->brandPanelOpen]) />
            </button>
            <p class="mt-1 text-[11px] leading-relaxed text-gray-500 dark:text-gray-400">
                {{ __('Product-wide email chrome (from name, color, footers). Not per event.') }}
            </p>

            @if ($this->brandPanelOpen)
                <div class="mt-3 space-y-3" wire:key="email-brand-panel">
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
                    <div class="flex flex-wrap items-center gap-2">
                        <x-filament::button wire:click="saveBrandSettings" color="gray" size="sm">
                            {{ __('Save brand') }}
                        </x-filament::button>
                        <a
                            href="{{ $this->communicationsSettingsUrl() }}"
                            class="text-xs font-medium text-primary-600 hover:underline dark:text-primary-400"
                        >
                            {{ __('Channel settings →') }}
                        </a>
                    </div>
                </div>
            @endif
        </div>
    </aside>

    {{-- Editor + preview --}}
    <div class="ff-templates-editor min-w-0">
        @if ($this->selectedTemplateKey === null)
            <div class="rounded-xl border border-dashed border-gray-200 px-4 py-10 text-center text-sm text-gray-500 dark:border-white/10 dark:text-gray-400">
                {{ __('Select a template from the list to edit.') }}
            </div>
        @else
            <header class="ff-templates-editor__header">
                <div class="ff-templates-editor__header-start min-w-0">
                    <button
                        type="button"
                        class="ff-templates-editor__back"
                        wire:click="showTemplatesList"
                    >
                        <x-heroicon-o-arrow-left class="h-4 w-4" />
                        <span>{{ __('Templates') }}</span>
                    </button>
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="truncate text-sm font-semibold text-gray-900 dark:text-white">
                                {{ $this->selectedTemplateLabel() }}
                            </h3>
                            @if ($this->templateDirty)
                                <span class="ff-templates-editor__unsaved">{{ __('Unsaved') }}</span>
                            @endif
                        </div>
                        <p class="mt-0.5 text-[11px] text-gray-500 dark:text-gray-400">
                            {{ $this->selectedTemplateAudienceLabel() }}
                        </p>
                    </div>
                </div>
                <div class="ff-templates-editor__actions">
                    @if ($this->templateDirty)
                        <x-filament::button wire:click="discardTemplateChanges" color="gray" size="sm" outlined>
                            {{ __('Discard') }}
                        </x-filament::button>
                    @endif
                    <x-filament::button wire:click="restoreTemplateDefaults" color="gray" size="sm" outlined>
                        {{ __('Restore defaults') }}
                    </x-filament::button>
                    <x-filament::button wire:click="saveTemplate" color="primary" size="sm">
                        {{ __('Save template') }}
                    </x-filament::button>
                </div>
            </header>

            <div class="ff-templates-editor__body mt-4 space-y-4">
                <div>
                    <p class="mb-1.5 text-xs font-semibold text-gray-600 dark:text-gray-300">{{ __('Channel') }}</p>
                    <div class="ff-templates-channel flex flex-wrap gap-1.5" role="group" aria-label="{{ __('Channel') }}">
                        @foreach ($channelShort as $familyKey => $familyLabel)
                            <button
                                type="button"
                                wire:click="selectChannelFamily('{{ $familyKey }}')"
                                @class([
                                    'ff-templates-channel__item',
                                    'ff-templates-channel__item--active' => $this->selectedChannelFamily === $familyKey,
                                ])
                            >
                                {{ $familyLabel }}
                            </button>
                        @endforeach
                    </div>
                    <p class="mt-1.5 text-xs leading-relaxed text-gray-500 dark:text-gray-400">
                        {{ $this->channelFamilyHelperText() }}
                    </p>
                </div>

                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <p class="mb-1.5 text-xs font-semibold text-gray-600 dark:text-gray-300">{{ __('Language') }}</p>
                        <div class="ff-templates-locale flex gap-1.5" role="group" aria-label="{{ __('Language') }}">
                            @foreach (['en' => 'EN', 'ar' => 'AR'] as $localeKey => $localeLabel)
                                <button
                                    type="button"
                                    wire:click="setEditLocale('{{ $localeKey }}')"
                                    @class([
                                        'ff-templates-locale__item',
                                        'ff-templates-locale__item--active' => $this->editLocale === $localeKey,
                                    ])
                                >
                                    {{ $localeLabel }}
                                </button>
                            @endforeach
                        </div>
                    </div>
                    <button
                        type="button"
                        class="ff-templates-compare hidden text-xs font-medium text-primary-600 hover:underline sm:inline dark:text-primary-400"
                        wire:click="toggleCompareLocales"
                    >
                        {{ $this->compareLocales ? __('Show one language') : __('Compare EN/AR') }}
                    </button>
                </div>

                @php
                    $showBoth = $this->compareLocales;
                    $localesToEdit = $showBoth ? ['en', 'ar'] : [$this->editLocale];
                @endphp

                <div @class(['grid gap-4', 'md:grid-cols-2' => $showBoth])>
                    @foreach ($localesToEdit as $locale)
                        @php
                            $subjectModel = $locale === 'ar' ? 'ar_subject' : 'en_subject';
                            $bodyModel = $locale === 'ar' ? 'ar_body' : 'en_body';
                            $dir = $locale === 'ar' ? 'rtl' : 'ltr';
                        @endphp
                        <div class="space-y-3 rounded-xl border border-gray-100 p-3 dark:border-white/10" dir="{{ $dir }}">
                            @if ($showBoth)
                                <h4 class="text-sm font-semibold text-gray-900 dark:text-white">
                                    {{ $locale === 'ar' ? __('Arabic') : __('English') }}
                                </h4>
                            @endif
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-300">
                                {{ $this->channelFamilySubjectLabel() }}
                                <input
                                    type="text"
                                    wire:model.live.debounce.400ms="{{ $subjectModel }}"
                                    class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5"
                                />
                            </label>
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-300">
                                {{ $this->channelFamilyBodyLabel() }}
                                <textarea
                                    wire:model.live.debounce.400ms="{{ $bodyModel }}"
                                    rows="8"
                                    class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5"
                                ></textarea>
                            </label>
                        </div>
                    @endforeach
                </div>

                @if ($this->selectedTemplateVariableList() !== [])
                    <div>
                        <p class="mb-1.5 text-xs font-semibold text-gray-600 dark:text-gray-300">
                            {{ __('Placeholders') }}
                        </p>
                        <p class="mb-2 text-[11px] text-gray-500 dark:text-gray-400">
                            {{ __('Tap a chip to append it to the active language body.') }}
                        </p>
                        <div class="flex flex-wrap gap-1.5">
                            @foreach ($this->selectedTemplateVariableList() as $variable)
                                @php($placeholder = '{{'.$variable.'}}')
                                <button
                                    type="button"
                                    wire:click="insertVariable('{{ $variable }}')"
                                    class="ff-templates-var-chip"
                                >
                                    {{ $placeholder }}
                                </button>
                            @endforeach
                        </div>
                    </div>
                @endif

                <div class="ff-templates-preview">
                    <button
                        type="button"
                        class="ff-templates-preview__toggle lg:pointer-events-none"
                        wire:click="togglePreviewExpanded"
                        aria-expanded="{{ $this->previewExpanded ? 'true' : 'false' }}"
                    >
                        <span class="text-sm font-semibold text-gray-900 dark:text-white">
                            {{ __('Preview (:locale)', ['locale' => strtoupper($this->previewLocale)]) }}
                            · {{ $this->channelFamilyOptions()[$this->selectedChannelFamily] ?? '' }}
                        </span>
                        <x-heroicon-o-chevron-down
                            @class([
                                'h-4 w-4 text-gray-500 transition lg:hidden',
                                'rotate-180' => $this->previewExpanded,
                            ])
                        />
                    </button>

                    <div @class([
                        'ff-templates-preview__panel mt-3',
                        'hidden' => ! $this->previewExpanded,
                        'lg:block' => true,
                    ])>
                        @if ($this->selectedChannelFamily === 'email')
                            <div class="ff-templates-preview__email" dir="{{ $this->previewLocale === 'ar' ? 'rtl' : 'ltr' }}">
                                <div
                                    class="ff-templates-preview__email-bar"
                                    style="background-color: {{ $this->brand_primary_color }}"
                                ></div>
                                <p class="ff-templates-preview__email-subject">{{ $this->previewSubject }}</p>
                                <div class="ff-templates-preview__email-body whitespace-pre-wrap">{{ $this->previewBody }}</div>
                                @if (filled($this->previewLocale === 'ar' ? $this->brand_footer_ar : $this->brand_footer_en))
                                    <p class="ff-templates-preview__email-footer">
                                        {{ $this->previewLocale === 'ar' ? $this->brand_footer_ar : $this->brand_footer_en }}
                                    </p>
                                @endif
                            </div>
                        @elseif ($this->selectedChannelFamily === 'in_app')
                            <div class="ff-templates-preview__bell" dir="{{ $this->previewLocale === 'ar' ? 'rtl' : 'ltr' }}">
                                <div class="ff-templates-preview__bell-icon" aria-hidden="true">
                                    <x-heroicon-o-bell class="h-5 w-5" />
                                </div>
                                <div class="min-w-0">
                                    <p class="font-semibold text-gray-900 dark:text-white">{{ $this->previewSubject }}</p>
                                    <p class="mt-1 whitespace-pre-wrap text-sm text-gray-600 dark:text-gray-300">{{ $this->previewBody }}</p>
                                </div>
                            </div>
                        @else
                            <div class="ff-templates-preview__sms" dir="{{ $this->previewLocale === 'ar' ? 'rtl' : 'ltr' }}">
                                @if (filled($this->previewSubject))
                                    <p class="mb-1 text-xs font-semibold text-gray-500">{{ $this->previewSubject }}</p>
                                @endif
                                <div class="ff-templates-preview__sms-bubble whitespace-pre-wrap">{{ $this->previewBody }}</div>
                                <p class="mt-2 text-[11px] text-gray-500 dark:text-gray-400">
                                    {{ __(':count characters', ['count' => $this->previewCharacterCount()]) }}
                                </p>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        @endif
    </div>
</div>
