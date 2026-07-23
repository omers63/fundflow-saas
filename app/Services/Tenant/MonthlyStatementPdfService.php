<?php

declare(strict_types=1);

namespace App\Services\Tenant;

use App\Models\Tenant\MonthlyStatement;
use App\Support\MemberLocale;
use App\Support\Pdf\DomPdfFactory;
use App\Support\Pdf\PdfAssets;
use App\Support\PublicPageSettings;
use App\Support\StatementSettings;
use Barryvdh\DomPDF\PDF as DomPdfDocument;
use Illuminate\Http\Response;

final class MonthlyStatementPdfService
{
    public function downloadResponse(MonthlyStatement $statement): Response
    {
        $statement->loadMissing(['member.user']);

        $memberUser = $statement->member?->user;

        if ($memberUser !== null) {
            return MemberLocale::usingPreferred(
                $memberUser,
                fn (): Response => $this->renderDownload($statement),
            );
        }

        return $this->renderDownload($statement);
    }

    public function binary(MonthlyStatement $statement): string
    {
        $statement->loadMissing(['member.user']);

        $memberUser = $statement->member?->user;

        if ($memberUser !== null) {
            return MemberLocale::usingPreferred(
                $memberUser,
                fn (): string => $this->renderBinary($statement),
            );
        }

        return $this->renderBinary($statement);
    }

    public function filename(MonthlyStatement $statement): string
    {
        return 'statement-'.$statement->period.'-'.$statement->member?->member_number.'.pdf';
    }

    private function renderDownload(MonthlyStatement $statement): Response
    {
        return $this->buildPdf($statement)->download($this->filename($statement));
    }

    private function renderBinary(MonthlyStatement $statement): string
    {
        return $this->buildPdf($statement)->output();
    }

    private function buildPdf(MonthlyStatement $statement): DomPdfDocument
    {
        $locale = app()->getLocale();
        $details = $statement->details ?? [];
        $fundNameEn = trim((string) ($details['fund_name_en'] ?? PublicPageSettings::fundName(locale: 'en')));
        $fundNameAr = trim((string) ($details['fund_name_ar'] ?? PublicPageSettings::fundName(locale: 'ar')));
        $fundName = $locale === 'ar'
            ? ($fundNameAr !== '' ? $fundNameAr : $fundNameEn)
            : ($fundNameEn !== '' ? $fundNameEn : $fundNameAr);

        if ($fundName === '') {
            $fundName = PublicPageSettings::fundName(locale: $locale);
        }

        $cfg = [
            'brand' => $fundName !== '' ? $fundName : StatementSettings::brandName(),
            'tagline' => StatementSettings::tagline(),
            'accent_color' => StatementSettings::accentColor(),
            'footer_disclaimer' => StatementSettings::footerDisclaimer(),
            'signature_line' => StatementSettings::signatureLine(),
            'include_txns' => StatementSettings::includeTransactions(),
            'include_loan' => StatementSettings::includeLoanSection(),
            'fund_name' => $fundName,
            'fund_name_en' => $fundNameEn,
            'fund_name_ar' => $fundNameAr,
        ];

        return DomPdfFactory::loadView('pdf.monthly-statement', [
            'statement' => $statement,
            'cfg' => $cfg,
            'accent' => $cfg['accent_color'],
            'logoDataUri' => PdfAssets::fundLogoDataUri(),
            'pdfFont' => StatementSettings::pdfFontFamily($locale),
        ]);
    }
}
