<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\Tenant\GatewayPayment;
use App\Support\Gateway\GatewayCheckout;
use App\Support\Gateway\GatewayWebhookEvent;
use Illuminate\Http\Request;

interface PaymentGatewayAdapter
{
    public function driver(): string;

    public function createPayment(GatewayPayment $payment): GatewayCheckout;

    public function verifyWebhook(Request $request): GatewayWebhookEvent;

    public function parseProviderRef(Request $request): ?string;
}
