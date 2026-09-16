<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\Central\Invoice;
use App\Models\Central\Plan;
use App\Models\Central\Tenant;
use App\Services\Billing\SaasBillingService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use InvalidArgumentException;

class SaasBillingPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $navigationLabel = 'SaaS billing';

    protected static ?string $slug = 'saas-billing';

    protected static ?int $navigationSort = 50;

    protected string $view = 'filament.pages.saas-billing';

    public static function canAccess(): bool
    {
        return auth()->check();
    }

    public function getTitle(): string
    {
        return __('SaaS billing');
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'plans' => Plan::query()->where('is_active', true)->orderBy('price')->get(),
            'invoices' => Invoice::query()->latest('id')->limit(25)->get(),
            'tenants' => Tenant::query()->orderBy('name')->limit(100)->get(),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('create_invoice')
                ->label(__('Create invoice'))
                ->icon(Heroicon::OutlinedPlus)
                ->form([
                    Select::make('tenant_id')
                        ->label(__('Tenant'))
                        ->options(fn() => Tenant::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->required()
                        ->searchable(),
                    Select::make('plan_id')
                        ->label(__('Plan'))
                        ->options(fn() => Plan::query()->where('is_active', true)->pluck('name', 'id')->all())
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $tenant = Tenant::query()->findOrFail($data['tenant_id']);
                    $plan = Plan::query()->findOrFail($data['plan_id']);
                    $invoice = app(SaasBillingService::class)->createInvoice($tenant, $plan);
                    Notification::make()
                        ->title(__('Invoice created'))
                        ->body(__('Checkout: :url', ['url' => app(SaasBillingService::class)->checkoutUrl($invoice)]))
                        ->success()
                        ->persistent()
                        ->send();
                }),
            Action::make('pay_latest')
                ->label(__('Fake checkout latest pending'))
                ->icon(Heroicon::OutlinedCreditCard)
                ->requiresConfirmation()
                ->action(function (): void {
                    $invoice = Invoice::query()->where('status', 'pending')->latest('id')->first();
                    if ($invoice === null) {
                        Notification::make()->title(__('No pending invoice'))->warning()->send();

                        return;
                    }

                    try {
                        app(SaasBillingService::class)->checkout($invoice);
                        Notification::make()->title(__('Invoice paid'))->success()->send();
                    } catch (InvalidArgumentException $e) {
                        Notification::make()->title(__('Checkout failed'))->body($e->getMessage())->danger()->send();
                    }
                }),
        ];
    }
}
