<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\MonthlyStatement;
use App\Models\Tenant\Setting;
use Barryvdh\DomPDF\Facade\Pdf;
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

        return $this->pdfResponse($statement);
    }

    public function admin(MonthlyStatement $statement): Response
    {
        return $this->pdfResponse($statement);
    }

    private function pdfResponse(MonthlyStatement $statement): Response
    {
        $statement->load('member');

        $cfg = [
            'brand' => Setting::get('statement', 'brand_name', config('app.name')),
            'tagline' => Setting::get('statement', 'tagline', __('Member fund statement')),
            'accent_color' => Setting::get('statement', 'accent_color', '#0284c7'),
            'footer_disclaimer' => Setting::get('statement', 'footer_disclaimer', __('Computer-generated statement. Confidential.')),
            'signature_line' => Setting::get('statement', 'signature_line', __('Fund administration')),
            'include_txns' => (bool) Setting::get('statement', 'include_transactions', true),
            'include_loan' => (bool) Setting::get('statement', 'include_loan_section', true),
        ];

        $pdf = Pdf::loadView('pdf.monthly-statement', [
            'statement' => $statement,
            'cfg' => $cfg,
        ]);

        $filename = 'statement-'.$statement->period.'-'.$statement->member?->member_number.'.pdf';

        return $pdf->download($filename);
    }
}
