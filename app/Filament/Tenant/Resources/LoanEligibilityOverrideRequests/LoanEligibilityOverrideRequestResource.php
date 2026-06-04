<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\LoanEligibilityOverrideRequests;

use App\Filament\Concerns\TranslatesFilamentNavigationLabels;
use App\Filament\Support\DatabaseNotificationsRefresh;
use App\Filament\Tenant\Clusters\LoansCluster;
use App\Filament\Tenant\Resources\LoanEligibilityOverrideRequests\Pages\ListLoanEligibilityOverrideRequests;
use App\Filament\Tenant\Resources\LoanEligibilityOverrideRequests\Tables\LoanEligibilityOverrideRequestsTable;
use App\Filament\Tenant\Resources\Loans\LoanResource;
use App\Models\Tenant\LoanEligibilityOverrideRequest;
use App\Models\Tenant\Member;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Livewire\Component;

class LoanEligibilityOverrideRequestResource extends Resource
{
    use TranslatesFilamentNavigationLabels;

    protected static ?string $model = LoanEligibilityOverrideRequest::class;

    protected static ?string $cluster = LoansCluster::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldExclamation;

    protected static ?string $modelLabel = 'Eligibility review request';

    protected static ?string $pluralModelLabel = 'Eligibility review requests';

    protected static bool $shouldRegisterNavigation = false;

    public static function canAccess(): bool
    {
        return auth()->guard('tenant')->check()
            && LoanEligibilityOverrideRequest::isTableReady();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return LoanEligibilityOverrideRequest::isTableReady();
    }

    public static function table(Table $table): Table
    {
        return LoanEligibilityOverrideRequestsTable::configure($table);
    }

    public static function getNavigationBadge(): ?string
    {
        if (! LoanEligibilityOverrideRequest::isTableReady()) {
            return null;
        }

        $count = LoanEligibilityOverrideRequest::pending()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    /**
     * @param  array<string, array<string, mixed>>  $filters
     */
    public static function listUrl(array $filters = []): string
    {
        return LoanResource::listUrl('eligibility_reviews', $filters);
    }

    public static function indexUrlForRequest(LoanEligibilityOverrideRequest $request): string
    {
        return LoanResource::listUrl('eligibility_reviews', [
            'status' => ['value' => 'pending'],
            'member_id' => ['value' => (string) $request->member_id],
        ]);
    }

    /**
     * @return array<string, array<string, string>>
     */
    public static function memberFilter(int|Member $member): array
    {
        $memberId = $member instanceof Member ? $member->getKey() : $member;

        return [
            'member_id' => [
                'value' => (string) $memberId,
            ],
        ];
    }

    public static function dispatchNotificationsRefresh(?Component $livewire): void
    {
        DatabaseNotificationsRefresh::dispatch($livewire);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLoanEligibilityOverrideRequests::route('/'),
        ];
    }
}
