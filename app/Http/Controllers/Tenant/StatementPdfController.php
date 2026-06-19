<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\MonthlyStatement;
use App\Support\MemberLocale;
use App\Support\Pdf\DomPdfFactory;
use App\Support\Pdf\PdfAssets;
use App\Support\StatementSettings;
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
        $statement->load(['member.user']);

        $memberUser = $statement->member?->user;

        if ($memberUser !== null) {
            return MemberLocale::using($memberUser, fn (): Response => $this->renderPdf($statement));
        }

        return $this->renderPdf($statement);
    }

    private function renderPdf(MonthlyStatement $statement): Response
    {
        $cfg = [
            'brand' => StatementSettings::brandName(),
            'tagline' => StatementSettings::tagline(),
            'accent_color' => StatementSettings::accentColor(),
            'footer_disclaimer' => StatementSettings::footerDisclaimer(),
            'signature_line' => StatementSettings::signatureLine(),
            'include_txns' => StatementSettings::includeTransactions(),
            'include_loan' => StatementSettings::includeLoanSection(),
        ];

        $pdf = DomPdfFactory::loadView('pdf.monthly-statement', [
            'statement' => $statement,
            'cfg' => $cfg,
            'accent' => $cfg['accent_color'],
            'logoDataUri' => PdfAssets::fundLogoDataUri(),
        ]);

        $filename = 'statement-'.$statement->period.'-'.$statement->member?->member_number.'.pdf';

        return $pdf->download($filename);
    }
}
