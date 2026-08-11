<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\Payment;
use App\Payments\PaymentGatewayFactory;
use App\Services\BusinessContext;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaymentWebhookController extends Controller
{
    public function __construct(
        protected PaymentService $paymentService
    ) {}

    /**
     * Handle incoming payment webhooks POST /api/payments/{provider}/webhook
     */
    public function handle(Request $request, string $provider): JsonResponse
    {
        try {
            $gateway = PaymentGatewayFactory::make($provider);
            $payment = $gateway->verifyWebhook($request);

            if (! $payment) {
                return response()->json([
                    'status' => 'ignored',
                    'message' => 'Payment reference not found or unverified.',
                ], 400);
            }

            /** @var Business $business */
            $business = $payment->business;

            return BusinessContext::runInContext($business, function () use ($payment) {
                if ($payment->status === 'paid') {
                    return response()->json([
                        'status' => 'success',
                        'message' => 'Payment was already processed.',
                    ]);
                }

                $this->paymentService->markAsPaid($payment);

                return response()->json([
                    'status' => 'success',
                    'payment_id' => $payment->id,
                    'payment_status' => 'paid',
                ]);
            });
        } catch (\Throwable $e) {
            Log::error("Error processing payment webhook for {$provider}: ".$e->getMessage(), [
                'exception' => $e,
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Internal server error processing webhook.',
            ], 500);
        }
    }
}
