@php
    $steps = $this->wizardStepDefinitions();
@endphp

<nav class="ff-legacy-wizard-stepper" aria-label="{{ __('Legacy migration wizard') }}">
    <ol class="ff-legacy-wizard-stepper__list">
        @foreach ($steps as $number => $step)
            @php
                $isActive = $currentStep === $number;
                $isComplete = $currentStep > $number;
            @endphp
            <li @class([
                'ff-legacy-wizard-stepper__item',
                'ff-legacy-wizard-stepper__item--active' => $isActive,
                'ff-legacy-wizard-stepper__item--complete' => $isComplete,
            ])>
                <button type="button" wire:click="goToStep({{ $number }})" class="ff-legacy-wizard-stepper__button">
                    <span class="ff-legacy-wizard-stepper__marker" aria-hidden="true">
                        @if ($isComplete)
                            <x-filament::icon icon="heroicon-m-check" class="h-4 w-4" />
                        @else
                            {{ $number }}
                        @endif
                    </span>
                    <span class="ff-legacy-wizard-stepper__text">
                        <span class="ff-legacy-wizard-stepper__label">{{ $step['label'] }}</span>
                        <span class="ff-legacy-wizard-stepper__description">{{ $step['description'] }}</span>
                    </span>
                </button>
            </li>
        @endforeach
    </ol>
</nav>