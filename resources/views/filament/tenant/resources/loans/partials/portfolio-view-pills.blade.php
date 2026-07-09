@php
    use App\Filament\Tenant\Resources\Loans\LoanResource;
    use App\Models\Tenant\LoanEligibilityOverrideRequest;

    $activeView = LoanResource::resolvePortfolioView();
    $pendingReviews = LoanResource::pendingEligibilityReviewsCount();
@endphp

@if (LoanEligibilityOverrideRequest::isTableReady())
    <div class="mb-4 space-y-2">
        <div class="ff-tenant-tab-pills flex flex-wrap gap-2">
            <a href="{{ LoanResource::listPortfolioViewUrl() }}" @class([
                'ff-tenant-tab-pills__item no-underline',
                'ff-tenant-tab-pills__item--active' => $activeView === null,
            ])>
                {{ __('All loans') }}
            </a>
            <a href="{{ LoanResource::listPortfolioViewUrl('eligibility') }}" @class([
                'ff-tenant-tab-pills__item no-underline',
                'ff-tenant-tab-pills__item--active' => $activeView === 'eligibility',
                'ff-tenant-tab-pills__item--warning' => $activeView !== 'eligibility' && $pendingReviews > 0,
            ])>
                {{ __('Eligibility reviews') }}
                @if ($pendingReviews > 0)
                    <span @class([
                        'ms-1 rounded-full px-1.5 py-0.5 text-[10px] font-bold tabular-nums',
                        'bg-sky-100 text-sky-700 dark:bg-sky-900/40 dark:text-sky-200' => $activeView === 'eligibility',
                        'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200' => $activeView !== 'eligibility',
                    ])>{{ $pendingReviews }}</span>
                @endif
            </a>
        </div>
    </div>
@endif