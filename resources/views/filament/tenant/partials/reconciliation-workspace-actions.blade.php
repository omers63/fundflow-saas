@props([
    'class' => '',
])

{{-- Single reconciliation action bar (no Simple / Advanced mode). --}}
@include('filament.tenant.partials.audit-system.workspace-actions', [
    'class' => $class,
])
