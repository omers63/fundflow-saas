@php
    use App\Filament\Tenant\Resources\Contributions\ContributionResource;

    $primaryTab = ContributionResource::resolvePrimaryTab();
@endphp

@if ($primaryTab === 'cycle')
    @include('filament.tenant.resources.contributions.partials.cycle-segment-pills')
@elseif ($primaryTab === 'ledger')
    @include('filament.tenant.resources.contributions.partials.ledger-view-pills')
@endif
