@php
    use App\Filament\Tenant\Resources\Loans\LoanResource;

    $primaryTab = LoanResource::resolvePrimaryTab();
@endphp

@if ($primaryTab === 'collection')
    @include('filament.tenant.resources.loans.partials.collection-segment-pills')
@elseif ($primaryTab === 'delinquency')
    @include('filament.tenant.resources.loans.partials.delinquency-view-pills')
@elseif ($primaryTab === 'portfolio')
    @include('filament.tenant.resources.loans.partials.portfolio-view-pills')
@endif