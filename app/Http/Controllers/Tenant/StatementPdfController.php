<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\MonthlyStatement;
use App\Services\Tenant\MonthlyStatementPdfService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class StatementPdfController extends Controller
{
    public function __invoke(Request $request, MonthlyStatement $statement): Response
    {
        $user = $request->user('tenant');
        $member = $user?->member;

        if ($member === null || (int) $statement->member_id !== (int) $member->id) {
            abort(403);
        }

        return app(MonthlyStatementPdfService::class)->downloadResponse($statement);
    }

    public function admin(MonthlyStatement $statement): Response
    {
        return app(MonthlyStatementPdfService::class)->downloadResponse($statement);
    }
}
