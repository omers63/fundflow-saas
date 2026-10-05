<x-filament-panels::page>
    <div class="space-y-6">
        <section class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <h3 class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('Two-factor authentication') }}</h3>
            <p class="mt-1 text-sm text-gray-500">
                {{ $twoFactorEnabled ? __('Enabled on this account.') : __('Not enabled yet.') }}
                · {{ __('Policy enforce:') }} {{ $enforceAdminTwoFactor ? __('On') : __('Off') }}
            </p>

            @if ($enrollmentSecret)
                <div class="mt-4 space-y-2 text-sm">
                    <p>{{ __('Add this secret in your authenticator app:') }} <code class="font-mono">{{ $enrollmentSecret }}</code></p>
                    <p class="break-all text-xs text-gray-500">{{ $enrollmentUri }}</p>
                    @if (count($recoveryCodes))
                        <p class="font-medium">{{ __('Recovery codes (store offline):') }}</p>
                        <ul class="font-mono text-xs">
                            @foreach ($recoveryCodes as $code)
                                <li>{{ $code }}</li>
                            @endforeach
                        </ul>
                    @endif
                    <p class="text-xs text-gray-500">{{ __('Then use Confirm two-factor with a 6-digit code.') }}</p>
                </div>
            @endif
        </section>

        <section class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <h3 class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('Active sessions') }}</h3>
            <ul class="mt-3 divide-y divide-gray-100 dark:divide-white/10">
                @forelse ($sessions as $session)
                    <li class="flex items-start justify-between gap-3 py-3 text-sm">
                        <div>
                            <div class="font-medium text-gray-900 dark:text-gray-100">
                                {{ $session->ip_address ?: __('Unknown IP') }}
                                @if ($session->is_current)
                                    <span class="text-xs text-primary-600">({{ __('This device') }})</span>
                                @endif
                            </div>
                            <div class="text-xs text-gray-500">{{ \Illuminate\Support\Str::limit($session->user_agent, 80) }}</div>
                            <div class="text-xs text-gray-400">{{ __('Last active') }}: {{ \Illuminate\Support\Carbon::createFromTimestamp($session->last_activity)->diffForHumans() }}</div>
                        </div>
                        @unless ($session->is_current)
                            <button type="button" wire:click="revokeSession('{{ $session->id }}')" class="text-xs font-medium text-danger-600 hover:underline">
                                {{ __('Revoke') }}
                            </button>
                        @endunless
                    </li>
                @empty
                    <li class="py-3 text-sm text-gray-500">{{ __('No database sessions found for this user.') }}</li>
                @endforelse
            </ul>
        </section>
    </div>

    @include('filament.tenant.partials.page-workspace-action-modals')
</x-filament-panels::page>
