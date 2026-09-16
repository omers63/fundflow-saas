<?php

declare(strict_types=1);

namespace App\Http\Resources\Tenant\Api;

use App\Models\Tenant\Loan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Loan */
final class LoanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'member_id' => $this->member_id,
            'status' => $this->status,
            'amount_requested' => (float) ($this->amount_requested ?? $this->amount ?? 0),
            'amount_approved' => (float) ($this->amount_approved ?? 0),
            'amount_disbursed' => (float) ($this->amount_disbursed ?? 0),
            'installments_count' => $this->installments_count,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'disbursed_at' => $this->disbursed_at?->toIso8601String(),
        ];
    }
}
