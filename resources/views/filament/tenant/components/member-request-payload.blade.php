@php
    /** @var \App\Models\Tenant\MemberRequest $record */
    $sections = \App\Filament\Support\MemberRequestViewSections::forAdmin($record);
@endphp
@include('filament.tenant.partials.view-record-modal', ['sections' => $sections])
