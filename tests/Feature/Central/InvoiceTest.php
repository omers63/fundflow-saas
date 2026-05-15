<?php

use App\Models\Central\Invoice;
use App\Models\Central\Plan;
use App\Models\Central\Tenant;

test('can create an invoice for a tenant', function () {
    $plan = Plan::factory()->create();
    $tenant = Tenant::factory()->createQuietly(['plan_id' => $plan->id]);

    $invoice = Invoice::factory()->create([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'amount' => 50.00,
        'status' => 'pending',
    ]);

    expect($invoice)
        ->toBeInstanceOf(Invoice::class)
        ->status->toBe('pending')
        ->amount->toEqual(50.00)
        ->tenant_id->toBe($tenant->id);

    $this->assertDatabaseHas('invoices', [
        'id' => $invoice->id,
        'invoice_number' => $invoice->invoice_number,
        'status' => 'pending',
    ], 'mysql');
});

test('invoice can be marked as paid', function () {
    $tenant = Tenant::factory()->createQuietly();
    $invoice = Invoice::factory()->create([
        'tenant_id' => $tenant->id,
        'status' => 'pending',
    ]);

    $paidAt = now();
    $invoice->update([
        'status' => 'paid',
        'paid_at' => $paidAt,
    ]);

    expect($invoice->fresh())
        ->status->toBe('paid')
        ->paid_at->not->toBeNull();
});
