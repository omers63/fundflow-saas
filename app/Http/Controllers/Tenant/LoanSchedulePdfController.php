<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Filament\Support\MoneyDisplay;
use App\Http\Controllers\Controller;
use App\Models\Tenant\Loan;
use App\Support\Insights\InsightFormatter;
use App\Support\MemberDateDisplay;
use App\Support\MemberLocale;
use App\Support\Pdf\DomPdfFactory;
use App\Support\Pdf\PdfAssets;
use App\Support\PublicPageSettings;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class LoanSchedulePdfController extends Controller
{
    public function __invoke(Request $request, Loan $loan): Response
    {
        $member = $request->user('tenant')?->member;

        if ($member === null || (int) $loan->member_id !== (int) $member->id || $loan->status !== 'active') {
            abort(403);
        }

        $loan->load(['member', 'guarantor', 'installments' => fn ($query) => $query->orderBy('installment_number')]);

        $user = $request->user('tenant');

        $render = function () use ($loan, $member): Response {
            $currency = InsightFormatter::currency();
            $outstanding = $loan->getOutstandingBalance();
            $installmentsPaid = $loan->installments->where('status', 'paid')->count();
            $installmentsTotal = $loan->installments->count();

            $pdf = DomPdfFactory::loadView('pdf.loan-schedule', [
                'loan' => $loan,
                'member' => $member,
                'currency' => $currency,
                'brand' => PublicPageSettings::fundName(locale: app()->getLocale()),
                'accent' => '#534ab7',
                'logoDataUri' => PdfAssets::fundLogoDataUri(),
                'outstanding' => $outstanding,
                'installmentsPaid' => $installmentsPaid,
                'installmentsTotal' => $installmentsTotal,
                'moneyHtml' => fn (float $amount): string => MoneyDisplay::pdfHtml($amount, $currency)?->toHtml() ?? '—',
                'formatDate' => fn (mixed $date, string $format = 'd M Y'): string => MemberDateDisplay::format($date, $format) ?? '—',
            ]);

            return $pdf->download('loan-schedule-'.$loan->id.'-'.$member->member_number.'.pdf');
        };

        return $user !== null
            ? MemberLocale::using($user, $render)
            : $render();
    }
}
