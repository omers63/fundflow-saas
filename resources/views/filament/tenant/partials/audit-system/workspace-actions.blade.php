@props([
    'names' => null,
    'class' => '',
])
@php
use Filament\Actions\Action;
use Filament\Support\Enums\Alignment;

$source = $this->getCachedWorkspacePanelActions();

$actions = collect($source)->filter(function ($action) use ($names): bool {
    if (!$action->isVisible()) {
        return false;
    }

    if ($names === null) {
        return true;
    }

    return $action instanceof Action
        && in_array($action->getName(), (array) $names, true);
})->values()->all();
@endphp
<div @class(['flex flex-wrap items-center justify-between gap-3', $class])>
    @if (filled($actions))
            <div class="ff-audit-workspace-actions min-w-0 flex-1">
                <x-filament::actions :actions="$actions" :alignment="Alignment::Start" />
        </div>
    @endif
                @if (method_exists($this, 'advancedUiAvailable'))
                    @include('filament.tenant.partials.advanced-ui-toggle')
                @endif
</div>
