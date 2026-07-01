{{-- Filament skips page action modals when the Livewire page implements HasTable (see panels page index). --}}
{{-- Workspace panel actions (e.g. Real-time snapshot) still use page-level Action::mount(), so include modals here.
--}}
<x-filament-actions::modals />