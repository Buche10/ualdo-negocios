<?php

namespace App\Payments;

use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PayPhoneGateway implements PaymentGateway
{
    public function createCharge(Payment $payment): array
    {
        $token = config('services.payphone.token');
        $externalRef = $payment->external_ref ?: 'pp_'.Str::random(12);
        $payment->update(['external_ref' => $externalRef]);

        if (empty($token)) {
            // Fallback for environment without live PayPhone token
            $url = url("/pay/{$payment->id}");

            return [
                'payment_url' => $url,
                'external_ref' => $externalRef,
                'status' => 'pending',
            ];
        }

        $response = Http::withToken($token)
            ->post('https://pay.payphonetodoesposible.com/api/button/Prepare', [
                'amount' => $payment->amount_cents,
                'amountWithTax' => 0,
                'amountWithoutTax' => $payment->amount_cents,
                'tax' => 0,
                'currency' => $payment->currency ?: 'USD',
                'clientTransactionId' => $externalRef,
                'responseUrl' => url("/pay/{$payment->id}"),
            ]);

        if ($response->successful() && isset($response->json()['payWithPayPhone'])) {
            $url = $response->json()['payWithPayPhone'];
        } else {
            $url = url("/pay/{$payment->id}");
        }

        return [
            'payment_url' => $url,
            'external_ref' => $externalRef,
            'status' => 'pending',
        ];
    }

    public function verifyWebhook(Request $request): ?Payment
    {
        $payphoneTxId = $request->input('id');
        $clientTxId = $request->input('clientTransactionId') ?? $request->input('clientTxId') ?? $request->input('external_ref');

        if (empty($clientTxId)) {
            Log::warning('PayPhone webhook rejected: Missing clientTransactionId.');

            return null;
        }

        /** @var Payment|null $payment */
        $payment = Payment::withoutGlobalScopes()
            ->where('external_ref', (string) $clientTxId)
            ->first();

        if (! $payment) {
            Log::warning("PayPhone webhook rejected: Payment with external_ref '{$clientTxId}' not found.");

            return null;
        }

        $token = config('services.payphone.token');

        if (! empty($token)) {
            // Confirm transaction with PayPhone API
            $response = Http::withToken($token)
                ->post('https://pay.payphonetodoesposible.com/api/button/V2/Confirm', [
                    'id' => (int) $payphoneTxId,
                    'clientTxId' => (string) $clientTxId,
                ]);

            if (! $response->successful()) {
                Log::error("PayPhone Confirm API error for payment #{$payment->id}: ".$response->body());

                return null;
            }

            $data = $response->json();
            $status = $data['transactionStatus'] ?? $data['status'] ?? null;
            $approved = ($status === 'Approved' || $status === 3 || $status === '3');

            if (! $approved) {
                Log::warning("PayPhone Confirm API rejected payment #{$payment->id}. Status: {$status}");

                return null;
            }

            // Endurecimiento: Validar que el monto pagado coincida con amount_cents
            $confirmedAmountCents = isset($data['amount']) ? (int) $data['amount'] : null;
            if ($confirmedAmountCents !== null && $confirmedAmountCents < $payment->amount_cents) {
                Log::warning("PayPhone amount mismatch for payment #{$payment->id}. Expected: {$payment->amount_cents}, Confirmed: {$confirmedAmountCents}");

                return null;
            }
        }

        return $payment;
    }
}
