<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessIntegration;
use App\Models\IntegrationMapping;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\User;
use App\Services\BusinessContext;
use App\Services\StaffInventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StaffInventoryServiceTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;

    protected User $staff;

    protected BusinessIntegration $integration;

    protected InventoryItem $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create([
            'name' => 'Restaurante Staff Service Test',
            'slug' => 'restaurante-staff-service-test',
            'vertical' => 'restaurant',
        ]);

        BusinessContext::set($this->business);

        $this->staff = User::factory()->create([
            'business_id' => $this->business->id,
            'name' => 'Carlos Bodeguero',
        ]);

        $this->integration = BusinessIntegration::create([
            'business_id' => $this->business->id,
            'provider' => 'doblefilo',
            'base_url' => 'https://doblefilo.vercel.app',
            'api_key' => 'staff_key_100',
            'enabled' => true,
        ]);

        $this->item = InventoryItem::create([
            'business_id' => $this->business->id,
            'name' => 'Bondiola de Cerdo',
            'type' => 'supply',
            'stock' => 10,
            'attributes' => ['unit' => 'kg', 'sku' => 'PRD-BONDIOLA'],
        ]);

        IntegrationMapping::create([
            'business_id' => $this->business->id,
            'provider' => 'doblefilo',
            'external_id' => 'PRD-BONDIOLA',
            'external_uid' => 'mongo_bondiola_777',
            'inventory_item_id' => $this->item->id,
        ]);
    }

    public function test_register_input_creates_local_ledger_and_pushes_to_doblefilo(): void
    {
        Http::fake([
            'https://doblefilo.vercel.app/api/inventory/movements' => Http::response([
                'success' => true,
            ], 201),
        ]);

        $service = app(StaffInventoryService::class);
        $res = $service->register(
            staff: $this->staff,
            item: $this->item,
            quantity: 20,
            kind: 'in',
            location: 'kitchen'
        );

        $this->assertEquals('success', $res['status']);
        $this->assertEquals(30, $res['new_stock']);
        $this->assertEquals('synced', $res['mirror_status']);

        // Assert local ledger updated
        $this->item->refresh();
        $this->assertEquals(30, $this->item->stock);

        $tx = InventoryTransaction::where('inventory_item_id', $this->item->id)->first();
        $this->assertNotNull($tx);
        $this->assertEquals(20, $tx->quantity);

        // Assert Doble Filo push sent
        Http::assertSent(function ($request) {
            return $request['productId'] === 'mongo_bondiola_777'
                && $request['movementType'] === 'input'
                && $request['quantity'] === 20;
        });
    }

    public function test_register_count_calculates_correct_delta(): void
    {
        Http::fake([
            'https://doblefilo.vercel.app/api/inventory/movements' => Http::response([
                'success' => true,
            ], 201),
        ]);

        $service = app(StaffInventoryService::class);
        // Initial stock = 10, physical count = 4 -> delta = -6
        $res = $service->register(
            staff: $this->staff,
            item: $this->item,
            quantity: 4,
            kind: 'count'
        );

        $this->assertEquals('success', $res['status']);
        $this->assertEquals(4, $res['new_stock']);
        $this->assertEquals(-6, $res['delta']);

        $this->item->refresh();
        $this->assertEquals(4, $this->item->stock);

        Http::assertSent(function ($request) {
            return $request['productId'] === 'mongo_bondiola_777'
                && $request['movementType'] === 'adjustment_out'
                && $request['quantity'] === 6;
        });
    }

    public function test_push_failure_retains_local_ledger_and_records_mirror_failure(): void
    {
        Http::fake([
            'https://doblefilo.vercel.app/api/inventory/movements' => Http::response([
                'error' => 'Doble Filo down',
            ], 500),
        ]);

        $service = app(StaffInventoryService::class);
        $res = $service->register(
            staff: $this->staff,
            item: $this->item,
            quantity: 5,
            kind: 'out'
        );

        $this->assertEquals('success', $res['status']);
        $this->assertEquals(5, $res['new_stock']);
        $this->assertEquals('failed', $res['mirror_status']);

        // Assert local ledger transaction is STILL retained
        $this->item->refresh();
        $this->assertEquals(5, $this->item->stock);
    }

    public function test_resolve_product_does_not_leak_cross_tenant_items_by_sku(): void
    {
        $otherBusiness = Business::create([
            'name' => 'Restaurante Competidor',
            'slug' => 'restaurante-competidor',
            'vertical' => 'restaurant',
        ]);

        // Create item in other business with same SKU
        InventoryItem::create([
            'business_id' => $otherBusiness->id,
            'name' => 'Lomo Fino Secreto',
            'type' => 'supply',
            'stock' => 50,
            'attributes' => ['unit' => 'kg', 'sku' => 'SKU-SECRET-99'],
        ]);

        $service = app(StaffInventoryService::class);

        // Search in current business context ($this->business)
        $resolved = $service->resolveProduct('SKU-SECRET-99');

        $this->assertNull($resolved);
    }
}
