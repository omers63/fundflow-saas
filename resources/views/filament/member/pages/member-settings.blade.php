<x-filament-panels::page>
    <div class="ff-member-settings space-y-4">
        <div class="ff-member-tab-bar flex flex-wrap gap-2">
            @foreach ([
                    'profile' => __('Account'),
                    'contributions' => __('Contributions'),
                    'notifications' => __('Notifications'),
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
    </div>
</x-filament-panels::page>
