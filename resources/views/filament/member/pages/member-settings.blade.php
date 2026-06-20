<x-filament-panels::page>
    <div class="ff-member-settings space-y-4">
        <div class="ff-member-tab-bar flex flex-wrap gap-2">
            @foreach ([
                'profile' => __('Profile'),
                'contributions' => __('Contributions'),
                'notifications' => __('Notifications'),
                'payout' => __('Payout details'),
            ] as $tab => $label)
                <button type="button" wire:click="setTab('{{ $tab }}')" @class([
                    'ff-member-tab-bar__item rounded-lg px-3 py-1.5 text-sm font-semibold transition',
                    'bg-primary-600 text-white' => $activeTab === $tab,
                    'bg-gray-100 text-gray-700 hover:bg-gray-200' => $activeTab !== $tab,
                ])>
                    {{ $label }}
                </button>
            @endforeach
        </div>

        <div wire:show="activeTab === 'profile'" class="ff-member-settings-tab">
            @include('filament.member.settings.profile-tab', [
                'user' => $profileUser,
                'member' => $profileMember,
                'householdProfiles' => $householdProfiles,
            ])
        </div>

        <div wire:show="activeTab === 'contributions'" class="ff-member-settings-tab">
            @include('filament.member.settings.contributions-body')
        </div>

        <div wire:show="activeTab === 'notifications'" class="ff-member-settings-tab">
            @include('filament.member.settings.notifications-tab')
        </div>

        <div wire:show="activeTab === 'payout'" class="ff-member-settings-tab">
            <x-member::panel :title="__('Payout bank details')">
                @if (filled($payoutIban))
                    <x-member::detail-grid :items="[
                        ['label' => __('Registered IBAN'), 'value' => $payoutIban],
                    ]" />
                    <p class="ff-member-dashboard-meta mt-3 mb-0">
                        {{ __('Cash-out withdrawals are sent to this IBAN. Contact support to update your payout details.') }}
                    </p>
                @else
                    <x-member::notice tone="blue">
                        <p class="m-0">
                            {{ __('No payout IBAN is on file. Contact fund administrators to register your bank account for cash-out withdrawals.') }}
                        </p>
                    </x-member::notice>
                @endif
            </x-member::panel>
        </div>
    </div>
</x-filament-panels::page>
