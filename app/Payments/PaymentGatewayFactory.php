<?php

namespace App\Payments;

class PaymentGatewayFactory
{
    public static function make(?string $provider = null): PaymentGateway
    {
        $provider = $provider ?? config('services.payment_provider', 'fake');

        return match (strtolower($provider)) {
            'payphone' => app(PayPhoneGateway::class),
            default => app(FakeGateway::class),
        };
    }
}
