<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\Payment;
use App\Services\BusinessContext;
use Illuminate\Http\JsonResponse;

class CheckoutController extends Controller
{
    /**
     * Show payment checkout details or status page for GET /pay/{payment}
     */
    public function show(int|string $paymentId): JsonResponse
    {
        /** @var Payment $payment */
        $payment = Payment::withoutGlobalScopes()
            ->with(['payable', 'contact', 'business'])
            ->where('id', $paymentId)
            ->firstOrFail();

        /** @var Business $business */
        $business = $payment->business;

        return BusinessContext::runInContext($business, function () use ($payment, $business) {
            return response()->json([
                'payment_id' => $payment->id,
                'business' => $business->name,
                'amount' => '$'.number_format($payment->amount_cents / 100, 2),
                'amount_cents' => $payment->amount_cents,
                'currency' => $payment->currency,
                'method' => $payment->method,
                'provider' => $payment->provider,
                'status' => $payment->status,
                'paid_at' => $payment->paid_at?->toDateTimeString(),
                'external_ref' => $payment->external_ref,
            ]);
        });
    }
}
