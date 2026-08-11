<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Business;
use App\Models\Contact;
use App\Models\Order;
use App\Models\Payment;
use App\Payments\PaymentGatewayFactory;
use Cknow\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class PaymentService
{
    /**
     * Generate a real payment link (PayPhone/Fake) or presencial payment registration.
     *
     * @return array<string, mixed>
     */
    public function generatePaymentLink(
        ?Contact $contact,
        Money|float|int $amount,
        string $description = 'Abono de Servicio',
        string $method = 'online',
        ?Model $payable = null
    ): array {
        /** @var Business|null $contactBusiness */
        $contactBusiness = $contact ? $contact->business : null;
        /** @var Business|null $business */
        $business = BusinessContext::get() ?? $contactBusiness;

        if (! $business) {
            return [
                'status' => 'error',
                'message' => 'No hay negocio activo configurado para procesar pagos.',
            ];
        }

        $money = $amount instanceof Money ? $amount : Money::USD((int) round(((float) $amount) * 100));
        $centsAmount = (int) $money->getAmount();
        $formattedAmount = number_format($centsAmount / 100, 2);

        $provider = $method === 'presencial' ? 'presencial' : config('services.payment_provider', 'fake');

        $payment = Payment::create([
            'business_id' => $business->id,
            'payable_type' => $payable ? $payable->getMorphClass() : null,
            'payable_id' => $payable?->getKey(),
            'contact_id' => $contact?->id,
            'provider' => $provider,
            'method' => $method,
            'amount_cents' => $centsAmount,
            'currency' => 'USD',
            'status' => 'pending',
        ]);

        if ($method === 'presencial') {
            return [
                'status' => 'success',
                'payment_id' => $payment->id,
                'method' => 'presencial',
                'business' => $business->name,
                'amount' => $formattedAmount,
                'cents' => $centsAmount,
                'currency' => 'USD',
                'description' => $description,
                'payment_url' => null,
                'message' => "Registro de pago presencial creado (#{$payment->id}) por \${$formattedAmount} USD. El cliente pagará directamente en el local.",
            ];
        }

        $gateway = PaymentGatewayFactory::make($provider);
        $charge = $gateway->createCharge($payment);

        return [
            'status' => 'success',
            'payment_id' => $payment->id,
            'method' => 'online',
            'business' => $business->name,
            'amount' => $formattedAmount,
            'cents' => $centsAmount,
            'currency' => 'USD',
            'description' => $description,
            'payment_url' => $charge['payment_url'],
            'external_ref' => $charge['external_ref'],
            'message' => "Enlace de pago generado por \${$formattedAmount} USD para '{$description}'. Enlace: {$charge['payment_url']}",
        ];
    }

    /**
     * Mark a pending payment as paid atomically (prevents race conditions across parallel webhooks).
     */
    public function markAsPaid(Payment $payment): Payment
    {
        return DB::transaction(function () use ($payment) {
            $affected = Payment::withoutGlobalScopes()
                ->where('id', $payment->id)
                ->where('status', '!=', 'paid')
                ->update([
                    'status' => 'paid',
                    'paid_at' => now(),
                ]);

            $payment->refresh();

            if ($affected === 0) {
                return $payment;
            }

            $payable = $payment->payable;
            if ($payable instanceof Order) {
                $payable->update(['payment_status' => 'paid']);
                if ($payable->status === 'draft') {
                    app(OrderService::class)->confirm($payable);
                }
            } elseif ($payable instanceof Appointment) {
                $payable->update(['payment_status' => 'paid']);
            }

            return $payment;
        });
    }
}
