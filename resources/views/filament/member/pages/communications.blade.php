<x-filament-panels::page>
    <div class="ff-member-communications space-y-4">
        <div class="ff-member-tab-bar flex flex-wrap gap-2">
            @foreach ([
                'messages' => __('Messages'),
                'requests' => __('Requests'),
                'alerts' => __('Alert history'),
                'faq' => __('FAQ'),
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

        @if ($activeTab === 'messages')
            @livewire(\App\Filament\Member\Widgets\MemberMessagesTableWidget::class, key('member-help-messages'))
        @elseif ($activeTab === 'requests')
            <x-member::notice tone="blue" :title="__('How can we help?')">
                <p class="m-0">
                    {{ __('Submit a support request or track household member requests below. Fund administrators are notified when you send a new request.') }}
                </p>
            </x-member::notice>

            @livewire(\App\Filament\Member\Widgets\MyMemberRequestsTableWidget::class, key('member-help-requests'))
        @elseif ($activeTab === 'alerts')
            @livewire(\App\Filament\Member\Widgets\MemberAlertHistoryTableWidget::class, key('member-help-alerts'))
        @else
            <x-member::panel :title="__('Frequently asked questions')">
                <p class="mb-3 text-sm text-gray-600">
                    {{ __('Quick answers about contributions, loans, deposits, and cash outs.') }}
                </p>
                <x-member::faq-accordion :items="$faqItems" />
            </x-member::panel>
        @endif
    </div>
</x-filament-panels::page>
