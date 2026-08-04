<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Contact;
use Illuminate\Support\Str;

class PaymentService
{
    /**
     * Generate a simulated payment link (PayPhone / Deuna / Stripe) for appointment deposit or service payment.
     */
    public function generatePaymentLink(?Contact $contact, float $amount, string $description = 'Abono de Reserva de Cita'): array
    {
        $business = BusinessContext::get() ?? $contact?->business ?? Business::first();
        $businessName = $business?->name ?? 'Consultorio';
        $transactionId = 'PAY-'.strtoupper(Str::random(8));
        $formattedAmount = number_format($amount, 2);

        // Generate clean simulated payment URL
        $paymentUrl = config('app.url', 'https://ualdo.app')."/pay/{$transactionId}?amount={$amount}&business={$business?->id}";

        return [
            'status' => 'success',
            'transaction_id' => $transactionId,
            'business' => $businessName,
            'amount' => $formattedAmount,
            'currency' => 'USD',
            'description' => $description,
            'payment_url' => $paymentUrl,
            'message' => "Se ha generado el enlace de abono por \${$formattedAmount} USD para '{$description}'. Enlace: {$paymentUrl}",
        ];
    }
}
