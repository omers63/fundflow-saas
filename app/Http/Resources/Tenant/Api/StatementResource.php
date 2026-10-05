<?php

declare(strict_types=1);

namespace App\Http\Resources\Tenant\Api;

use App\Models\Tenant\MonthlyStatement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MonthlyStatement */
final class StatementResource extends JsonResource
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
            'opening_balance' => (float) ($this->opening_balance ?? 0),
            'total_contributions' => (float) ($this->total_contributions ?? 0),
            'total_repayments' => (float) ($this->total_repayments ?? 0),
            'closing_balance' => (float) ($this->closing_balance ?? 0),
            'generated_at' => $this->generated_at?->toIso8601String(),
        ];
    }
}
