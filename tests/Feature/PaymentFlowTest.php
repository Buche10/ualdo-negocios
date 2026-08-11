<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Contact;
use App\Models\Order;
use App\Models\Payment;
use App\Services\BusinessContext;
use App\Services\PaymentService;
use Cknow\Money\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PaymentFlowTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create([
            'name' => 'Negocio Pagos Test',
            'slug' => 'negocio-pagos-test',
            'vertical' => 'health',
            'whatsapp_phone_number_id' => 'phone_pay_123',
        ]);

        BusinessContext::set($this->business);
    }

    public function test_online_payment_creates_payment_record_and_returns_valid_url(): void
    {
        $contact = Contact::create(['phone_number' => '593977665544', 'name' => 'Diana Rios']);
        $service = app(PaymentService::class);

        $res = $service->generatePaymentLink(
            contact: $contact,
            amount: Money::USD(3500),
            description: 'Abono Limpieza Facial',
            method: 'online'
        );

        $this->assertEquals('success', $res['status']);
        $this->assertEquals('online', $res['method']);
        $this->assertNotNull($res['payment_url']);
        $this->assertStringContainsString('/pay/', $res['payment_url']);

        $payment = Payment::withoutGlobalScopes()->find($res['payment_id']);
        $this->assertNotNull($payment);
        $this->assertEquals(3500, $payment->amount_cents);
        $this->assertEquals('pending', $payment->status);
        $this->assertEquals('online', $payment->method);
    }

    public function test_presencial_payment_creates_record_without_external_url(): void
    {
        $contact = Contact::create(['phone_number' => '593977665544', 'name' => 'Diana Rios']);
        $service = app(PaymentService::class);

        $res = $service->generatePaymentLink(
            contact: $contact,
            amount: Money::USD(5000),
            description: 'Pago en Local',
            method: 'presencial'
        );

        $this->assertEquals('success', $res['status']);
        $this->assertEquals('presencial', $res['method']);
        $this->assertNull($res['payment_url']);

        $payment = Payment::withoutGlobalScopes()->find($res['payment_id']);
        $this->assertNotNull($payment);
        $this->assertEquals('presencial', $payment->method);
        $this->assertEquals('presencial', $payment->provider);
        $this->assertEquals('pending', $payment->status);
    }

    public function test_payment_webhook_updates_payment_status_idempotently_without_context_leak(): void
    {
        $contact = Contact::create(['phone_number' => '593977665544', 'name' => 'Diana Rios']);
        $order = Order::create([
            'business_id' => $this->business->id,
            'contact_id' => $contact->id,
            'status' => 'draft',
            'payment_status' => 'pending',
            'total_cents' => 4500,
        ]);

        $payment = Payment::create([
            'business_id' => $this->business->id,
            'payable_type' => Order::class,
            'payable_id' => $order->id,
            'contact_id' => $contact->id,
            'provider' => 'fake',
            'method' => 'online',
            'amount_cents' => 4500,
            'external_ref' => 'REF_PAYPHONE_999',
            'status' => 'pending',
        ]);

        // CRÍTICO: Limpiar BusinessContext para simular request público de producción
        BusinessContext::clear();
        $this->assertNull(BusinessContext::get());

        // Primera llamada al webhook
        $response = $this->postJson('/api/payments/fake/webhook', [
            'clientTransactionId' => 'REF_PAYPHONE_999',
            'status' => 'Approved',
        ]);

        $response->assertStatus(200);
        $response->assertJson(['status' => 'success']);

        $payment = Payment::withoutGlobalScopes()->find($payment->id);
        $order = Order::withoutGlobalScopes()->find($order->id);

        $this->assertEquals('paid', $payment->status);
        $this->assertNotNull($payment->paid_at);
        $this->assertEquals('paid', $order->payment_status);
        $this->assertEquals('confirmed', $order->status);

        // Segunda llamada idéntica (Idempotencia en contexto limpio)
        BusinessContext::clear();
        $response2 = $this->postJson('/api/payments/fake/webhook', [
            'clientTransactionId' => 'REF_PAYPHONE_999',
            'status' => 'Approved',
        ]);

        $response2->assertStatus(200);
        $response2->assertJson(['status' => 'success', 'message' => 'Payment was already processed.']);
    }

    public function test_checkout_endpoint_returns_payment_details_without_active_context(): void
    {
        $payment = Payment::create([
            'business_id' => $this->business->id,
            'provider' => 'fake',
            'method' => 'online',
            'amount_cents' => 2500,
            'status' => 'pending',
            'external_ref' => 'REF_CHECKOUT_123',
        ]);

        // CRÍTICO: Limpiar BusinessContext para simular request público del cliente
        BusinessContext::clear();
        $this->assertNull(BusinessContext::get());

        $response = $this->getJson("/pay/{$payment->id}");

        $response->assertStatus(200);
        $response->assertJson([
            'payment_id' => $payment->id,
            'business' => 'Negocio Pagos Test',
            'amount' => '$25.00',
            'status' => 'pending',
        ]);
    }

    public function test_payphone_webhook_verifies_transaction_with_payphone_confirm_api(): void
    {
        config(['services.payphone.token' => 'mock_payphone_token']);

        $payment = Payment::create([
            'business_id' => $this->business->id,
            'provider' => 'payphone',
            'method' => 'online',
            'amount_cents' => 5000,
            'external_ref' => 'PP_CLIENT_TX_777',
            'status' => 'pending',
        ]);

        // Simular respuesta exitosa de la API Confirm de PayPhone
        Http::fake([
            'https://pay.payphonetodoesposible.com/api/button/V2/Confirm' => Http::response([
                'transactionStatus' => 'Approved',
                'clientTransactionId' => 'PP_CLIENT_TX_777',
                'amount' => 5000,
            ], 200),
        ]);

        BusinessContext::clear();

        $response = $this->postJson('/api/payments/payphone/webhook', [
            'id' => 123456,
            'clientTransactionId' => 'PP_CLIENT_TX_777',
        ]);

        $response->assertStatus(200);
        $response->assertJson(['status' => 'success']);

        $payment = Payment::withoutGlobalScopes()->find($payment->id);
        $this->assertEquals('paid', $payment->status);
    }

    public function test_payphone_webhook_rejects_unverified_or_failed_payphone_confirm_response(): void
    {
        config(['services.payphone.token' => 'mock_payphone_token']);

        $payment = Payment::create([
            'business_id' => $this->business->id,
            'provider' => 'payphone',
            'method' => 'online',
            'amount_cents' => 5000,
            'external_ref' => 'PP_CLIENT_TX_888',
            'status' => 'pending',
        ]);

        // Simular rechazo en la API Confirm de PayPhone
        Http::fake([
            'https://pay.payphonetodoesposible.com/api/button/V2/Confirm' => Http::response([
                'transactionStatus' => 'Canceled',
            ], 400),
        ]);

        BusinessContext::clear();

        $response = $this->postJson('/api/payments/payphone/webhook', [
            'id' => 999999,
            'clientTransactionId' => 'PP_CLIENT_TX_888',
        ]);

        $response->assertStatus(400);
        $response->assertJson(['status' => 'ignored']);

        $payment = Payment::withoutGlobalScopes()->find($payment->id);
        $this->assertEquals('pending', $payment->status);
    }

    public function test_payphone_webhook_rejects_amount_mismatch(): void
    {
        config(['services.payphone.token' => 'mock_payphone_token']);

        $payment = Payment::create([
            'business_id' => $this->business->id,
            'provider' => 'payphone',
            'method' => 'online',
            'amount_cents' => 5000, // $50.00 esperados
            'external_ref' => 'PP_CLIENT_TX_MISMATCH',
            'status' => 'pending',
        ]);

        // Simular respuesta de PayPhone confirmando un monto inferior ($10.00 = 1000 centavos)
        Http::fake([
            'https://pay.payphonetodoesposible.com/api/button/V2/Confirm' => Http::response([
                'transactionStatus' => 'Approved',
                'clientTransactionId' => 'PP_CLIENT_TX_MISMATCH',
                'amount' => 1000,
            ], 200),
        ]);

        BusinessContext::clear();

        $response = $this->postJson('/api/payments/payphone/webhook', [
            'id' => 888777,
            'clientTransactionId' => 'PP_CLIENT_TX_MISMATCH',
        ]);

        $response->assertStatus(400);
        $response->assertJson(['status' => 'ignored']);

        $payment = Payment::withoutGlobalScopes()->find($payment->id);
        $this->assertEquals('pending', $payment->status);
    }
}
