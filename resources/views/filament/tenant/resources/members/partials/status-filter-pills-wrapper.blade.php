@php
    use App\Filament\Tenant\Resources\Members\MemberResource;
    use App\Services\Tenant\MemberListTabService;

    $tabs = app(MemberListTabService::class)->pillTabs()->all();
    $activeTab = MemberResource::resolveListTab();
@endphp

@include('filament.tenant.resources.members.partials.status-filter-pills', [
    'tabs' => $tabs,
    'activeTab' => $activeTab,
])
