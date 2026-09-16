<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Tenant\Api\MemberBalanceResource;
use App\Models\Tenant\Member;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class BalancesController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Member::query()
            ->with(['cashAccount', 'fundAccount'])
            ->orderBy('member_number');

        return MemberBalanceResource::collection(
            $query->paginate(min(100, max(1, (int) $request->integer('per_page', 25)))),
        );
    }

    public function show(Member $member): MemberBalanceResource
    {
        $member->loadMissing(['cashAccount', 'fundAccount']);

        return new MemberBalanceResource($member);
    }
}
