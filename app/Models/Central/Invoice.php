<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * Invoice model records every payment transaction from a Tenant to the Central app.
 */
class Invoice extends Model
{
    use CentralConnection, HasFactory;

    protected $fillable = [
        'invoice_number',
        'tenant_id',
        'subscription_id',
        'plan_id',
        'amount',
        'currency',
        'status', // pending, paid, failed, cancelled
        'description',
        'payment_method',
        'paid_at',
        'metadata',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
        'metadata' => 'array',
    ];

    /**
     * The tenant being billed.
     */
    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * The plan purchased in this invoice.
     */
    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * Checks if the invoice is paid.
     */
    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }
}
