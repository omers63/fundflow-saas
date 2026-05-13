<?php

namespace App\Filament\Pages;

use App\Models\Central\Invoice;
use App\Models\Central\Plan;
use App\Models\Central\Subscription;
use App\Models\Central\Tenant;
use App\Services\TenantProvisioningService;
use BackedEnum;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class PurchasePlan extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-shopping-cart';

    protected static ?string $navigationLabel = 'Purchase Plan';

    protected static ?string $slug = 'purchase-plan';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.purchase-plan';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        // Explicitly deny access to super_admin for this page.
        // super_admin bypasses all authorization by default, so we must explicitly check and deny here.
        if (auth()->user()->hasRole('super_admin')) {
            return false;
        }

        return auth()->user()->hasRole('user');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return self::canAccess();
    }

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Select a Plan')
                    ->schema([
                        Radio::make('plan_id')
                            ->label('Choose your subscription plan')
                            ->options(Plan::where('is_active', true)->get()->pluck('name', 'id'))
                            ->descriptions(Plan::where('is_active', true)->get()->mapWithKeys(fn ($plan) => [
                                $plan->id => $plan->currency.' '.number_format($plan->price, 2).' / '.$plan->billing_cycle,
                            ]))
                            ->required()
                            ->live(),
                    ]),

                Section::make('Tenant Details')
                    ->description('Set up your business workspace')
                    ->schema([
                        TextInput::make('tenant_name')
                            ->label('Business Name')
                            ->required()
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (string $operation, $state, $set) => $set('subdomain', Str::slug($state))),
                        TextInput::make('subdomain')
                            ->label('Subdomain')
                            ->required()
                            ->prefix('https://')
                            ->suffix('.'.config('tenancy.central_domain'))
                            ->unique(table: 'tenants', column: 'id')
                            ->regex('/^[a-z0-9\-]+$/')
                            ->helperText('Only lowercase letters, numbers, and hyphens.'),
                        TextInput::make('custom_domain')
                            ->label('Custom Domain')
                            ->placeholder('example.com')
                            ->visible(fn (callable $get) => Plan::find($get('plan_id'))?->support_custom_domain ?? false)
                            ->unique(table: 'domains', column: 'domain')
                            ->helperText('Enter your own domain name if your plan supports it.'),
                    ]),
            ])
            ->statePath('data');
    }

    public function submit(TenantProvisioningService $provisioningService): void
    {
        $data = $this->form->getState();

        $plan = Plan::find($data['plan_id']);

        // 1. Create Tenant (Is not provisioned yet)
        $tenant = Tenant::create([
            'id' => $data['subdomain'],
            'name' => $data['tenant_name'],
            'central_user_id' => Auth::id(),
            'plan_id' => $plan->id,
            'is_provisioned' => false,
            'domain_name' => $data['custom_domain'] ?? null,
        ]);

        // 2. Create Pending Invoice
        $invoice = Invoice::create([
            'invoice_number' => 'INV-'.strtoupper(Str::random(10)),
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'amount' => $plan->price,
            'currency' => $plan->currency,
            'status' => 'pending',
            'description' => "Subscription for {$plan->name} Plan",
        ]);

        // 3. Create Pending Subscription
        Subscription::create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => 'pending',
            'starts_at' => now(),
            'ends_at' => now()->addMonths($plan->duration_months),
        ]);

        Notification::make()
            ->title('Order placed successfully!')
            ->body('Your invoice '.$invoice->invoice_number.' has been generated. Please complete the payment to activate your workspace.')
            ->success()
            ->send();

        $this->redirect('/admin/invoices'); // Redirect to invoices list
    }
}
