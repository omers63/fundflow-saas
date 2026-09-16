<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Central\Invoice;
use App\Models\Central\Plan;
use App\Models\Central\Subscription;
use App\Models\Central\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Central SaaS invoicing + fake self-serve checkout (no external PSP required).
 */
final class SaasBillingService
{
    public function createInvoice(Tenant $tenant, Plan $plan, ?string $description = null): Invoice
    {
        $subscription = $tenant->subscription;

        return Invoice::query()->create([
            'invoice_number' => 'INV-' . strtoupper(Str::random(10)),
            'tenant_id' => $tenant->id,
            'subscription_id' => $subscription?->id,
            'plan_id' => $plan->id,
            'amount' => $plan->price,
            'currency' => $plan->currency ?? 'SAR',
            'status' => 'pending',
            'description' => $description ?? __('Subscription: :plan', ['plan' => $plan->name]),
            'payment_method' => null,
            'metadata' => ['checkout' => 'self_serve'],
        ]);
    }

    /**
     * Fake checkout: marks invoice paid and extends/creates subscription.
     */
    public function checkout(Invoice $invoice, string $paymentMethod = 'fake_card'): Invoice
    {
        if ($invoice->isPaid()) {
            return $invoice;
        }

        if ($invoice->status !== 'pending') {
            throw new InvalidArgumentException(__('Only pending invoices can be checked out.'));
        }

        return DB::transaction(function () use ($invoice, $paymentMethod): Invoice {
            $invoice->forceFill([
                'status' => 'paid',
                'payment_method' => $paymentMethod,
                'paid_at' => now(),
            ])->save();

            $tenant = $invoice->tenant;
            $plan = $invoice->plan;
            if ($tenant === null || $plan === null) {
                return $invoice->fresh() ?? $invoice;
            }

            $months = max(1, (int) ($plan->duration_months ?? 1));
            $subscription = $tenant->subscription;

            if ($subscription === null) {
                Subscription::query()->create([
                    'tenant_id' => $tenant->id,
                    'plan_id' => $plan->id,
                    'status' => 'active',
                    'starts_at' => now(),
                    'ends_at' => now()->addMonthsNoOverflow($months),
                ]);
            } else {
                $base = $subscription->ends_at && $subscription->ends_at->isFuture()
                    ? $subscription->ends_at
                    : now();
                $subscription->forceFill([
                    'plan_id' => $plan->id,
                    'status' => 'active',
                    'ends_at' => $base->copy()->addMonthsNoOverflow($months),
                    'canceled_at' => null,
                ])->save();
            }

            $tenant->forceFill(['plan_id' => $plan->id])->save();

            return $invoice->fresh() ?? $invoice;
        });
    }

    public function checkoutUrl(Invoice $invoice): string
    {
        return url('/admin/saas-checkout/' . $invoice->id);
    }
}
