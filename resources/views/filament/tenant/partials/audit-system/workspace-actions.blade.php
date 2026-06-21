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
@if (filled($actions))
    <div @class(['ff-audit-workspace-actions', $class])>
        <x-filament::actions :actions="$actions" :alignment="Alignment::Start" />
    </div>
@endif
