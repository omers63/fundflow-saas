<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Tenant\Api\MemberResource;
use App\Models\Tenant\Member;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class MembersController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Member::query()->orderBy('member_number');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        return MemberResource::collection($query->paginate(min(100, max(1, (int) $request->integer('per_page', 25)))));
    }

    public function show(Member $member): MemberResource
    {
        return new MemberResource($member);
    }
}
