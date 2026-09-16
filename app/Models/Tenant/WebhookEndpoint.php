<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class WebhookEndpoint extends Model
{
    /** @var list<string> */
    public const EVENTS = [
        'contribution.posted',
        'loan.approved',
        'recon.exception.raised',
        'gateway.payment.paid',
    ];

    protected $fillable = [
        'name',
        'url',
        'secret',
        'events',
        'is_active',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'events' => 'array',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (WebhookEndpoint $endpoint): void {
            if (blank($endpoint->secret)) {
                $endpoint->secret = Str::random(40);
            }
        });
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    public function listensFor(string $event): bool
    {
        $events = $this->events ?? [];

        return $events === [] || in_array('*', $events, true) || in_array($event, $events, true);
    }
}
