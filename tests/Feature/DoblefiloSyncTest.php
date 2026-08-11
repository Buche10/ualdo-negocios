<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessIntegration;
use App\Models\IntegrationMapping;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Services\BusinessContext;
use App\Services\IntegrationSyncService;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DoblefiloSyncTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;

    protected BusinessIntegration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create([
            'name' => 'Restaurante El Asador',
            'slug' => 'restaurante-el-asador',
            'vertical' => 'restaurant',
        ]);

        BusinessContext::set($this->business);

        $this->integration = BusinessIntegration::create([
            'business_id' => $this->business->id,
            'provider' => 'doblefilo',
            'base_url' => 'https://doblefilo.vercel.app',
            'api_key' => 'secret_key_999',
            'enabled' => true,
        ]);
    }

    public function test_first_sync_creates_items_and_mappings(): void
    {
        Http::fake([
            'https://doblefilo.vercel.app/api/inventory*' => Http::response([
                'success' => true,
                'data' => [
                    [
                        'code' => 'PRD-001',
                        'name' => 'Lomo de Res Premium',
                        'unit' => 'kg',
                        'categoryName' => 'Carnes',
                        'minStock' => 10,
                        'isActive' => true,
                        'inventory' => ['available' => 14.75],
                    ],
                    [
                        'code' => 'PRD-002',
                        'name' => 'Salsa BBQ Casera',
                        'unit' => 'unit',
                        'categoryName' => 'Salsas',
                        'minStock' => 5,
                        'isActive' => true,
                        'inventory' => ['available' => 20],
                    ],
                ],
                'meta' => ['page' => 1, 'pages' => 1],
            ], 200),
        ]);

        $service = app(IntegrationSyncService::class);
        $syncRun = $service->pull($this->integration);

        $this->assertEquals('success', $syncRun->status);
        $this->assertEquals(2, $syncRun->pulled);
        $this->assertEquals(2, $syncRun->created_count);
        $this->assertEquals(0, $syncRun->updated_count);
        $this->assertEquals(0, $syncRun->skipped);

        // Assert InventoryItems created
        $item1 = InventoryItem::where('name', 'Lomo de Res Premium')->first();
        $this->assertNotNull($item1);
        $this->assertEquals('supply', $item1->type);
        $this->assertEquals(15, $item1->stock); // 14.75 rounded
        $this->assertEquals(10, $item1->min_stock);
        $this->assertEquals('PRD-001', $item1->attributes['sku']);
        $this->assertEquals('kg', $item1->attributes['unit']);
        $this->assertEquals(14.75, $item1->attributes['available_exact']);

        // Assert IntegrationMapping created
        $mapping1 = IntegrationMapping::where('external_id', 'PRD-001')->first();
        $this->assertNotNull($mapping1);
        $this->assertEquals($item1->id, $mapping1->inventory_item_id);
        $this->assertNotNull($mapping1->external_hash);

        // Assert InventoryTransaction created via ledger
        $tx = InventoryTransaction::where('inventory_item_id', $item1->id)->first();
        $this->assertNotNull($tx);
        $this->assertEquals(15, $tx->quantity);
        $this->assertEquals(15, $tx->balance_after);
        $this->assertEquals('adjust', $tx->type);
    }

    public function test_stock_change_flows_through_ledger(): void
    {
        $availableStock = 10;

        Http::fake([
            'https://doblefilo.vercel.app/api/inventory*' => function () use (&$availableStock) {
                return Http::response([
                    'success' => true,
                    'data' => [
                        [
                            'code' => 'PRD-001',
                            'name' => 'Lomo de Res',
                            'unit' => 'kg',
                            'inventory' => ['available' => $availableStock],
                        ],
                    ],
                    'meta' => ['page' => 1, 'pages' => 1],
                ], 200);
            },
        ]);

        $service = app(IntegrationSyncService::class);

        // 1st Sync: stock = 10
        $service->pull($this->integration);

        $item = InventoryItem::where('name', 'Lomo de Res')->first();
        $this->assertEquals(10, $item->stock);

        // 2nd Sync: stock changes to 25 (+15 delta)
        $availableStock = 25;

        $syncRun2 = $service->pull($this->integration);
        // dd($syncRun2->toArray());
        $this->assertEquals('success', $syncRun2->status);
        $this->assertEquals(0, $syncRun2->created_count);
        $this->assertEquals(1, $syncRun2->updated_count);

        $item->refresh();
        $this->assertEquals(25, $item->stock);

        // Check ledger transactions (Initial +10, Adjustment +15)
        $txs = InventoryTransaction::where('inventory_item_id', $item->id)->orderBy('id')->get();
        $this->assertCount(2, $txs);
        $this->assertEquals(15, $txs[1]->quantity);
        $this->assertEquals(25, $txs[1]->balance_after);
    }

    public function test_idempotent_second_run_skips_unchanged(): void
    {
        Http::fake([
            'https://doblefilo.vercel.app/api/inventory*' => Http::response([
                'success' => true,
                'data' => [
                    [
                        'code' => 'PRD-001',
                        'name' => 'Vino Tinto Reserva',
                        'unit' => 'unit',
                        'inventory' => ['available' => 12],
                    ],
                ],
                'meta' => ['page' => 1, 'pages' => 1],
            ], 200),
        ]);

        $service = app(IntegrationSyncService::class);
        $service->pull($this->integration);

        // Run 2: Exact same payload
        $syncRun2 = $service->pull($this->integration);

        $this->assertEquals('success', $syncRun2->status);
        $this->assertEquals(0, $syncRun2->created_count);
        $this->assertEquals(0, $syncRun2->updated_count);
        $this->assertEquals(1, $syncRun2->skipped);
    }

    public function test_multitenant_isolation(): void
    {
        Http::fake([
            'https://doblefilo.vercel.app/api/inventory*' => Http::response([
                'success' => true,
                'data' => [
                    [
                        'code' => 'PRD-TENANT-A',
                        'name' => 'Aceite Vegetal Negocio A',
                        'unit' => 'unit',
                        'inventory' => ['available' => 5],
                    ],
                ],
                'meta' => ['page' => 1, 'pages' => 1],
            ], 200),
        ]);

        $service = app(IntegrationSyncService::class);
        $service->pull($this->integration);

        // Switch to Business B context
        $businessB = Business::create(['name' => 'Negocio B', 'slug' => 'negocio-b', 'vertical' => 'restaurant']);
        BusinessContext::set($businessB);

        $itemsB = InventoryItem::all();
        $this->assertCount(0, $itemsB);

        $mappingsB = IntegrationMapping::all();
        $this->assertCount(0, $mappingsB);
    }

    public function test_partial_failure_records_errors_but_continues(): void
    {
        Http::fake([
            'https://doblefilo.vercel.app/api/inventory*' => Http::response([
                'success' => true,
                'data' => [
                    [
                        'code' => 'PRD-FAIL-01',
                        'name' => 'Producto Con Error Ledger',
                        'unit' => 'kg',
                        'inventory' => ['available' => 10],
                    ],
                    [
                        'code' => 'PRD-OK-02',
                        'name' => 'Producto Valido',
                        'unit' => 'unit',
                        'inventory' => ['available' => 5],
                    ],
                ],
                'meta' => ['page' => 1, 'pages' => 1],
            ], 200),
        ]);

        $mockInventoryService = $this->partialMock(InventoryService::class, function ($mock) {
            $mock->shouldReceive('adjustStock')
                ->andReturnUsing(function ($item, $delta, $type, $reason, $source) {
                    if (str_contains($item->name, 'Error')) {
                        throw new \RuntimeException('Error simulado en libro contable de inventario');
                    }

                    return new InventoryTransaction([
                        'business_id' => $item->business_id,
                        'inventory_item_id' => $item->id,
                        'type' => $type,
                        'quantity' => $delta,
                        'balance_after' => $delta,
                    ]);
                });
        });

        $service = new IntegrationSyncService($mockInventoryService);
        $syncRun = $service->pull($this->integration);

        $this->assertEquals('partial', $syncRun->status);
        $this->assertEquals(2, $syncRun->pulled);
        $this->assertEquals(1, $syncRun->created_count);
        $this->assertNotNull($syncRun->errors);
        $this->assertCount(1, $syncRun->errors);
        $this->assertEquals('PRD-FAIL-01', $syncRun->errors[0]['external_id']);
        $this->assertStringContainsString('Error simulado', $syncRun->errors[0]['error']);

        // Assert that valid product PRD-OK-02 was created despite PRD-FAIL-01 failing
        $validItem = InventoryItem::where('name', 'Producto Valido')->first();
        $this->assertNotNull($validItem);
    }

    public function test_pull_protects_local_stock_when_push_is_pending_failed(): void
    {
        $item = InventoryItem::create([
            'business_id' => $this->business->id,
            'name' => 'Bondiola Protegida',
            'type' => 'supply',
            'stock' => 17, // Employee logged consumption locally: 20 -> 17 (-3)
            'attributes' => ['unit' => 'kg', 'sku' => 'PRD-BONDIOLA-P'],
        ]);

        IntegrationMapping::create([
            'business_id' => $this->business->id,
            'provider' => 'doblefilo',
            'external_id' => 'PRD-BONDIOLA-P',
            'external_uid' => 'mongo_bondiola_p_1',
            'inventory_item_id' => $item->id,
            'external_hash' => 'old_hash',
        ]);

        // Un-synced failed transaction push
        InventoryTransaction::create([
            'business_id' => $this->business->id,
            'inventory_item_id' => $item->id,
            'type' => 'adjust',
            'quantity' => -3,
            'balance_after' => 17,
            'reason' => 'Consumo Telegram',
            'mirror_status' => 'failed',
            'mirror_payload' => [
                'type' => 'output',
                'quantity' => 3,
                'unit' => 'kg',
                'location' => 'kitchen',
            ],
        ]);

        // Doble Filo still has old stock = 20 (since push failed) and push retry fails with 500
        Http::fake([
            'https://doblefilo.vercel.app/api/inventory/movements' => Http::response([], 500),
            'https://doblefilo.vercel.app/api/inventory*' => Http::response([
                'success' => true,
                'data' => [
                    [
                        '_id' => 'mongo_bondiola_p_1',
                        'code' => 'PRD-BONDIOLA-P',
                        'name' => 'Bondiola Protegida',
                        'unit' => 'kg',
                        'inventory' => ['available' => 20],
                    ],
                ],
                'meta' => ['page' => 1, 'pages' => 1],
            ], 200),
        ]);

        $service = app(IntegrationSyncService::class);
        $syncRun = $service->pull($this->integration);

        $this->assertEquals('success', $syncRun->status);

        // Local stock MUST remain 17 (protected from pull reversion!)
        $item->refresh();
        $this->assertEquals(17, $item->stock);
    }
}
