<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Central\Invoice;
use App\Services\Billing\SaasBillingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class SaasCheckoutController extends Controller
{
    public function __invoke(Request $request, Invoice $invoice, SaasBillingService $billing): RedirectResponse
    {
        abort_unless(auth()->check(), 403);

        $paid = $billing->checkout($invoice, (string) $request->input('payment_method', 'fake_card'));

        return redirect('/admin/saas-billing')
            ->with('status', __('Invoice :number paid.', ['number' => $paid->invoice_number]));
    }
}
