<?php

declare(strict_types=1);

namespace App\Http\Resources\Tenant\Api;

use App\Models\Tenant\Member;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Member */
final class MemberBalanceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'member_id' => $this->id,
            'member_number' => $this->member_number,
            'name' => $this->name,
            'cash_balance' => round($this->getCashBalance(), 2),
            'fund_balance' => round($this->getFundBalance(), 2),
        ];
    }
}
