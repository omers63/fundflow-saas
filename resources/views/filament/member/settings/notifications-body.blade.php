@php
    $profileUrl = $profileUrl ?? \App\Filament\Member\Pages\MemberSettingsPage::getUrl(['tab' => 'profile']);
    $sysChannels = [
        ['in_app', '🔔', __('In-App Inbox'), __('Notifications inside the member portal.'), 'bg-violet-100 text-violet-700 dark:bg-violet-900/30 dark:text-violet-300'],
        ['push', '📲', __('Push'), __('Browser or device push notifications when enabled.'), 'bg-fuchsia-100 text-fuchsia-700 dark:bg-fuchsia-900/30 dark:text-fuchsia-300'],
        ['email', '✉️', __('Email'), __('Sent to your registered email address.'), 'bg-sky-100 text-sky-700 dark:bg-sky-900/30 dark:text-sky-300'],
        ['sms', '📱', __('SMS'), __('Text message to your registered phone.'), 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300'],
        ['whatsapp', '💬', __('WhatsApp'), __('WhatsApp message to your registered phone.'), 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300'],
    ];
@endphp
        <div
            class="mb-6 rounded-xl bg-gradient-to-br from-sky-100 via-white to-indigo-50 dark:from-slate-800 dark:via-sky-950/35 dark:to-indigo-950/30 ring-1 ring-sky-200/80 dark:ring-sky-600/40 p-5 shadow-md">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-4">
                {{ __('Available Communication Channels') }}
            </h3>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
                @foreach ($sysChannels as [$ch, $icon, $label, $desc, $style])
                    @php $chEnabled = $this->isSystemEnabled($ch); @endphp
                    <div @class([
                        'relative flex items-start gap-3 rounded-lg p-3',
                        $style => $chEnabled,
                        'bg-gray-100 dark:bg-gray-700/40 text-gray-400 dark:text-gray-500' => !$chEnabled,
                    ])>
                        @if (!$chEnabled)
                            <div
                                class="absolute inset-0 rounded-lg flex items-center justify-center bg-gray-100/80 dark:bg-gray-800/80 z-10">
                                <span
                                    class="text-xs font-semibold text-red-500 dark:text-red-400 bg-white dark:bg-gray-800 px-2 py-0.5 rounded-full ring-1 ring-red-200 dark:ring-red-700">
                                    {{ __('Unavailable') }}
                                </span>
                            </div>
                        @endif
                        <span class="text-lg leading-none mt-0.5">{{ $icon }}</span>
                        <div>
                            <div class="font-semibold text-sm">{{ $label }}</div>
                            <div class="text-xs opacity-75 mt-0.5">{{ $desc }}</div>
                        </div>
                    </div>
                @endforeach
            </div>
            <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                {{ __('SMS and WhatsApp require a valid phone number in your profile.') }}
                {!! __('Channels marked <span class="font-semibold text-amber-600 dark:text-amber-400">Required</span> cannot be disabled — they carry critical information.') !!}
                {!! __('Channels marked <span class="font-semibold text-red-500 dark:text-red-400">Unavailable</span> have been disabled by the administrator.') !!}
            </p>
        </div>
        
        <div class="space-y-3">
            <div class="hidden lg:grid lg:grid-cols-12 gap-4 px-5 pb-1">
                <div class="col-span-3 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    {{ __('Notification Category') }}
                </div>
                <div
                    class="col-span-9 grid grid-cols-5 gap-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 text-center">
                    <div>🔔 {{ __('In-App') }}</div>
                    <div>📲 {{ __('Push') }}</div>
                    <div>✉️ {{ __('Email') }}</div>
                    <div>📱 {{ __('SMS') }}</div>
                    <div>💬 {{ __('WhatsApp') }}</div>
                </div>
            </div>
        
            @foreach ($this->categories as $type => $meta)
                    @php
                        $channels = ['in_app', 'push', 'email', 'sms', 'whatsapp'];
                        $channelLabels = ['in_app' => __('In-App'), 'push' => __('Push'), 'email' => __('Email'), 'sms' => __('SMS'), 'whatsapp' => __('WhatsApp')];
                        $channelIcons = ['in_app' => '🔔', 'push' => '📲', 'email' => '✉️', 'sms' => '📱', 'whatsapp' => '💬'];
                    @endphp
                    <div wire:key="cat-{{ $type }}"
                        class="rounded-xl bg-white dark:bg-gray-800 ring-1 ring-gray-200 dark:ring-gray-700 shadow-sm overflow-hidden">
                        <div class="lg:grid lg:grid-cols-12 lg:gap-4 lg:items-center p-5">
                            <div class="lg:col-span-3 mb-4 lg:mb-0">
                                <div class="flex items-center gap-2 mb-1">
                                    <x-dynamic-component :component="$meta['icon']" class="w-4 h-4 text-gray-500 dark:text-gray-400" />
                                    <span class="font-semibold text-sm text-gray-900 dark:text-white">{{ $meta['label'] }}</span>
                                </div>
                                <p class="text-xs text-gray-500 dark:text-gray-400 leading-relaxed">{{ $meta['description'] }}</p>
                                @if (!empty($meta['forced']))
                                    <div class="mt-2 flex flex-wrap gap-1">
                                        @foreach ($meta['forced'] as $fc)
                                            <span
                                                class="inline-flex items-center gap-1 rounded-full bg-amber-100 dark:bg-amber-900/30 px-2 py-0.5 text-xs font-medium text-amber-700 dark:text-amber-300">
                                                {{ $channelIcons[$fc] ?? '' }} {{ $channelLabels[$fc] ?? $fc }}: {{ __('Required') }}
                                            </span>
                                        @endforeach
                                    </div>
                                @endif
                            </div>

                            <div class="lg:col-span-9 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
                            @foreach ($channels as $ch)
                                @php
                $supported = $this->isSupported($type, $ch);
                $forced = $this->isForced($type, $ch);
                $enabled = $this->isEnabled($type, $ch);
                $sysEnabled = $this->isSystemEnabled($ch);
                $clickable = $supported && !$forced && $sysEnabled;
                                @endphp
                                <button wire:click="{{ $clickable ? 'toggleChannel(\'' . $type . '\', \'' . $ch . '\')' : '' }}"
                                    type="button" @disabled(!$clickable)
                                    title="{{ !$sysEnabled ? __('Disabled by administrator') : ($forced ? __('Required — cannot be disabled') : ($supported ? '' : __('Not available for this category'))) }}"
                                    @class([
                    'relative flex flex-col items-center gap-1.5 rounded-xl border-2 px-3 py-3 text-center transition-all duration-150',
                    'cursor-not-allowed opacity-50 border-red-200 dark:border-red-800/40 bg-red-50/50 dark:bg-red-950/10' => !$sysEnabled,
                    'cursor-default opacity-40 border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900/20' => $sysEnabled && !$supported,
                    'cursor-not-allowed border-amber-300 bg-amber-50 dark:border-amber-700/50 dark:bg-amber-950/20' => $sysEnabled && $supported && $forced,
                    'cursor-pointer hover:shadow-md active:scale-95 border-primary-500 bg-primary-50 dark:bg-primary-900/20 shadow-sm' => $sysEnabled && $supported && !$forced && $enabled,
                    'cursor-pointer hover:shadow-md active:scale-95 border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900/20' => $sysEnabled && $supported && !$forced && !$enabled,
                ])>
                                    <span class="text-xl leading-none">{{ $channelIcons[$ch] }}</span>
                                    <span @class([
                    'text-xs font-semibold',
                    'text-red-400 dark:text-red-500' => !$sysEnabled,
                    'text-amber-600 dark:text-amber-400' => $sysEnabled && $forced,
                    'text-primary-700 dark:text-primary-300' => $sysEnabled && $enabled && $supported && !$forced,
                    'text-gray-500 dark:text-gray-400' => $sysEnabled && (!$enabled || !$supported) && !$forced,
                ])>
                                        {{ $channelLabels[$ch] }}
                                    </span>

                                    @if (!$sysEnabled)
                                        <span
                                            class="text-[10px] font-medium text-red-500 dark:text-red-400 leading-none">{{ __('Admin Off') }}</span>
                                    @elseif ($forced)
                                        <span
                                            class="text-[10px] font-medium text-amber-600 dark:text-amber-400 leading-none">{{ __('Required') }}</span>
                                    @elseif (!$supported)
                                        <span class="text-[10px] text-gray-400 dark:text-gray-500 leading-none">{{ __('N/A') }}</span>
                                    @elseif ($enabled)
                                        <span
                                            class="text-[10px] font-medium text-primary-600 dark:text-primary-400 leading-none">{{ __('On') }}</span>
                                    @else
                                        <span class="text-[10px] text-gray-400 dark:text-gray-500 leading-none">{{ __('Off') }}</span>
                                    @endif

                                    @if ($enabled && $supported && $sysEnabled)
                                        <div
                                            class="absolute -top-1.5 -right-1.5 flex h-4 w-4 items-center justify-center rounded-full bg-primary-500 text-white shadow">
                                            <svg class="h-2.5 w-2.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                                                stroke-width="3">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                                            </svg>
                                        </div>
                                    @endif
                                </button>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endforeach
</div>

<div
    class="mt-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between rounded-xl bg-white dark:bg-gray-800 ring-1 ring-gray-200 dark:ring-gray-700 p-5 shadow-sm">
    <div>
        <p class="text-sm font-medium text-gray-900 dark:text-white">{{ __('Ready to save?') }}</p>
        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
            {{ __('Changes take effect immediately. Forced channels are always included regardless of your selection.') }}
        </p>
        @if ($savedAt)
            <p class="text-xs text-emerald-600 dark:text-emerald-400 font-medium mt-1">
                {{ __('✓ Saved at :time', ['time' => $savedAt]) }}
            </p>
        @endif
    </div>
    <button wire:click="save" wire:loading.attr="disabled" type="button"
        class="inline-flex items-center gap-2 rounded-lg bg-primary-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 disabled:opacity-50 transition-all">
        <span wire:loading wire:target="save"
            class="h-4 w-4 animate-spin rounded-full border-2 border-white border-t-transparent"></span>
        <span wire:loading.remove wire:target="save">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
            </svg>
        </span>
        {{ __('Save Preferences') }}
    </button>
</div>

<div class="mt-4 rounded-lg bg-blue-50 dark:bg-blue-950/30 ring-1 ring-blue-200 dark:ring-blue-800/40 p-4">
    <p class="text-xs text-blue-700 dark:text-blue-300 leading-relaxed">
        <strong>{{ __('Tips:') }}</strong>
        {!! __('Make sure your phone number is set in <a href=":url" wire:navigate class="underline">your profile</a> to receive SMS and WhatsApp notifications.', ['url' => $profileUrl]) !!}
        {{ __('You can update your preferences at any time. Changes are reflected on the next notification sent.') }}
    </p>
</div>
