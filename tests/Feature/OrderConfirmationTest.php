<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Contact;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\BusinessContext;
use App\Services\OrderService;
use Cknow\Money\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class OrderConfirmationTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create([
            'name' => 'Restaurante / Retail Test Business',
            'slug' => 'test-retail-business',
            'vertical' => 'restaurant',
            'whatsapp_phone_number_id' => 'phone_order_123',
        ]);

        BusinessContext::set($this->business);
    }

    public function test_confirming_order_deducts_product_stock_and_logs_sale_transaction(): void
    {
        $contact = Contact::create(['phone_number' => '593988776655', 'name' => 'Carlos Lopez']);

        $product = InventoryItem::create([
            'business_id' => $this->business->id,
            'name' => 'Camiseta Polo M',
            'type' => 'product',
            'stock' => 15,
            'min_stock' => 2,
            'price' => Money::USD(2500),
        ]);

        $order = Order::create([
            'business_id' => $this->business->id,
            'contact_id' => $contact->id,
            'status' => 'draft',
            'payment_method' => 'online',
            'payment_status' => 'pending',
            'subtotal_cents' => 5000,
            'total_cents' => 5000,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'inventory_item_id' => $product->id,
            'name' => $product->name,
            'unit_price_cents' => 2500,
            'quantity' => 2,
            'line_total_cents' => 5000,
        ]);

        $orderService = app(OrderService::class);
        $confirmedOrder = $orderService->confirm($order);

        $this->assertEquals('confirmed', $confirmedOrder->status);
        $this->assertNotNull($confirmedOrder->placed_at);

        $product->refresh();
        $this->assertEquals(13, $product->stock);

        $tx = InventoryTransaction::where('inventory_item_id', $product->id)->first();
        $this->assertNotNull($tx);
        $this->assertEquals('sale', $tx->type);
        $this->assertEquals(-2, $tx->quantity);
        $this->assertEquals(13, $tx->balance_after);
        $this->assertEquals(Order::class, $tx->source_type);
        $this->assertEquals($order->id, $tx->source_id);
    }

    public function test_insufficient_stock_prevents_order_confirmation(): void
    {
        $contact = Contact::create(['phone_number' => '593988776655', 'name' => 'Carlos Lopez']);

        $product = InventoryItem::create([
            'business_id' => $this->business->id,
            'name' => 'Zapatos Cuero',
            'type' => 'product',
            'stock' => 1,
            'min_stock' => 1,
            'price' => Money::USD(8000),
        ]);

        $order = Order::create([
            'business_id' => $this->business->id,
            'contact_id' => $contact->id,
            'status' => 'draft',
            'payment_method' => 'online',
            'payment_status' => 'pending',
            'subtotal_cents' => 16000,
            'total_cents' => 16000,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'inventory_item_id' => $product->id,
            'name' => $product->name,
            'unit_price_cents' => 8000,
            'quantity' => 2,
            'line_total_cents' => 16000,
        ]);

        $orderService = app(OrderService::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Stock insuficiente');

        try {
            $orderService->confirm($order);
        } finally {
            $product->refresh();
            $this->assertEquals(1, $product->stock); // Invariable tras rollback
            $this->assertDatabaseMissing('inventory_transactions', ['inventory_item_id' => $product->id]);
        }
    }
}
