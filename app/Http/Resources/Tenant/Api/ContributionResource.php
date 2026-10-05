<?php

declare(strict_types=1);

namespace App\Http\Resources\Tenant\Api;

use App\Models\Tenant\Contribution;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Contribution */
final class ContributionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'member_id' => $this->member_id,
            'period' => $this->period,
            'amount' => (float) $this->amount,
            'status' => $this->status,
            'collection_status' => $this->collection_status,
            'posted_at' => $this->posted_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
        ];
    }
}
