<?php

namespace Tests\Feature;

use App\Integrations\DoblefiloConnector;
use App\Models\Business;
use App\Models\BusinessIntegration;
use App\Services\BusinessContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class DoblefiloConnectorTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;

    protected BusinessIntegration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create([
            'name' => 'Restaurante Doble Filo Fan',
            'slug' => 'restaurante-doble-filo-fan',
            'vertical' => 'restaurant',
        ]);

        BusinessContext::set($this->business);

        $this->integration = BusinessIntegration::create([
            'business_id' => $this->business->id,
            'provider' => 'doblefilo',
            'base_url' => 'https://doblefilo.vercel.app',
            'api_key' => 'test-secret-key-123',
            'enabled' => true,
        ]);
    }

    public function test_pull_maps_products_to_canonical_items(): void
    {
        Http::fake([
            'https://doblefilo.vercel.app/api/inventory*' => Http::response([
                'success' => true,
                'data' => [
                    [
                        'code' => 'PRD-000101',
                        'name' => 'Carne Molida Especial',
                        'unit' => 'kg',
                        'categoryName' => 'Carnes',
                        'minStock' => 5,
                        'isActive' => true,
                        'requiresWeightControl' => true,
                        'inventory' => [
                            'total' => 6.92,
                            'available' => 6.92,
                            'reserved' => 0,
                        ],
                    ],
                    [
                        'code' => 'PRD-000102',
                        'name' => 'Queso Mozzarella Block',
                        'unit' => 'unit',
                        'categoryName' => 'Lácteos',
                        'minStock' => 2,
                        'isActive' => false,
                        'requiresWeightControl' => false,
                        'inventory' => [
                            'total' => 12,
                            'available' => 10,
                            'reserved' => 2,
                        ],
                    ],
                ],
                'meta' => [
                    'page' => 1,
                    'limit' => 100,
                    'total' => 2,
                    'pages' => 1,
                ],
            ], 200),
        ]);

        $connector = new DoblefiloConnector($this->integration);
        $items = $connector->pull();

        $this->assertCount(2, $items);

        $item1 = $items[0];
        $this->assertEquals('PRD-000101', $item1->externalId);
        $this->assertEquals('Carne Molida Especial', $item1->name);
        $this->assertEquals('kg', $item1->unit);
        $this->assertEquals('Carnes', $item1->category);
        $this->assertEquals(7, $item1->stock); // 6.92 rounded to 7
        $this->assertEquals(6.92, $item1->stockExact);
        $this->assertEquals(5, $item1->minStock);
        $this->assertTrue($item1->isActive);

        $item2 = $items[1];
        $this->assertEquals('PRD-000102', $item2->externalId);
        $this->assertEquals(10, $item2->stock);
        $this->assertEquals(10.0, $item2->stockExact);
        $this->assertFalse($item2->isActive);
    }

    public function test_pull_paginates_until_last_page(): void
    {
        Http::fake([
            'https://doblefilo.vercel.app/api/inventory?page=1*' => Http::response([
                'success' => true,
                'data' => [
                    [
                        'code' => 'PRD-P1-01',
                        'name' => 'Harina Fina',
                        'unit' => 'kg',
                        'inventory' => ['available' => 25],
                    ],
                ],
                'meta' => ['page' => 1, 'pages' => 2],
            ], 200),
            'https://doblefilo.vercel.app/api/inventory?page=2*' => Http::response([
                'success' => true,
                'data' => [
                    [
                        'code' => 'PRD-P2-01',
                        'name' => 'Aceite de Oliva',
                        'unit' => 'unit',
                        'inventory' => ['available' => 10],
                    ],
                ],
                'meta' => ['page' => 2, 'pages' => 2],
            ], 200),
        ]);

        $connector = new DoblefiloConnector($this->integration);
        $items = $connector->pull();

        $this->assertCount(2, $items);
        $this->assertEquals('PRD-P1-01', $items[0]->externalId);
        $this->assertEquals('PRD-P2-01', $items[1]->externalId);
    }

    public function test_pull_throws_on_api_failure(): void
    {
        $this->expectException(RuntimeException::class);

        Http::fake([
            'https://doblefilo.vercel.app/api/inventory*' => Http::response(['error' => 'Server error'], 500),
        ]);

        $connector = new DoblefiloConnector($this->integration);
        $connector->pull();
    }

    public function test_pull_sends_api_key_header(): void
    {
        Http::fake([
            'https://doblefilo.vercel.app/api/inventory*' => Http::response([
                'success' => true,
                'data' => [],
                'meta' => ['page' => 1, 'pages' => 1],
            ], 200),
        ]);

        $connector = new DoblefiloConnector($this->integration);
        $connector->pull();

        Http::assertSent(function ($request) {
            return $request->hasHeader('x-api-key', 'test-secret-key-123');
        });
    }

    public function test_pull_handles_object_category_fallback(): void
    {
        Http::fake([
            'https://doblefilo.vercel.app/api/inventory*' => Http::response([
                'success' => true,
                'data' => [
                    [
                        'code' => 'PRD-OBJ-CAT',
                        'name' => 'Tomate Riñón',
                        'unit' => 'kg',
                        'category' => ['_id' => 'cat_123', 'name' => 'Vegetales', 'slug' => 'vegetales'],
                        'inventory' => ['available' => 15],
                    ],
                ],
                'meta' => ['page' => 1, 'pages' => 1],
            ], 200),
        ]);

        $connector = new DoblefiloConnector($this->integration);
        $items = $connector->pull();

        $this->assertCount(1, $items);
        $this->assertEquals('Vegetales', $items[0]->category);
    }
}
