<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Tenant\Api\LoanResource;
use App\Models\Tenant\Loan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class LoansController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Loan::query()->with('member')->orderByDesc('id');

        if ($request->filled('member_id')) {
            $query->where('member_id', (int) $request->integer('member_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        return LoanResource::collection(
            $query->paginate(min(100, max(1, (int) $request->integer('per_page', 25)))),
        );
    }

    public function show(Loan $loan): LoanResource
    {
        $loan->loadMissing('member');

        return new LoanResource($loan);
    }
}
