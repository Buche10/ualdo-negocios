<?php

namespace App\Payments;

use App\Models\Payment;
use Illuminate\Http\Request;

interface PaymentGateway
{
    /**
     * Create a payment charge/link for the given Payment model.
     *
     * @return array{payment_url: string|null, external_ref: string, status: string}
     */
    public function createCharge(Payment $payment): array;

    /**
     * Verify incoming webhook request and return the matching Payment model if verified.
     */
    public function verifyWebhook(Request $request): ?Payment;
}
