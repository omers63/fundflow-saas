<?php

declare(strict_types=1);

namespace App\Http\Resources\Tenant\Api;

use App\Models\Tenant\Member;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Member */
final class MemberResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'member_number' => $this->member_number,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'status' => $this->status,
            'joined_at' => $this->joined_at?->toDateString(),
            'monthly_contribution_amount' => (float) ($this->monthly_contribution_amount ?? 0),
        ];
    }
}
