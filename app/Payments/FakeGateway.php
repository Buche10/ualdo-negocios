<?php

namespace App\Payments;

use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class FakeGateway implements PaymentGateway
{
    public function createCharge(Payment $payment): array
    {
        $externalRef = $payment->external_ref ?: 'fake_ref_'.Str::random(12);
        $payment->update(['external_ref' => $externalRef]);

        $url = url("/pay/{$payment->id}");

        return [
            'payment_url' => $url,
            'external_ref' => $externalRef,
            'status' => 'pending',
        ];
    }

    public function verifyWebhook(Request $request): ?Payment
    {
        $externalRef = $request->input('external_ref') ?? $request->input('id') ?? $request->input('clientTransactionId');
        if (empty($externalRef)) {
            return null;
        }

        /** @var Payment|null $payment */
        $payment = Payment::withoutGlobalScopes()
            ->where('external_ref', (string) $externalRef)
            ->first();

        if (! $payment) {
            Log::warning("FakeGateway webhook rejected: Payment with external_ref '{$externalRef}' not found.");

            return null;
        }

        return $payment;
    }
}
