<?php

namespace Tests\Feature;

use App\Jobs\RetryDoblefiloPushJob;
use App\Models\Business;
use App\Models\BusinessIntegration;
use App\Models\IntegrationMapping;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Services\BusinessContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RetryDoblefiloPushJobTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;

    protected BusinessIntegration $integration;

    protected InventoryItem $item;

    protected InventoryTransaction $transaction;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create([
            'name' => 'Restaurante Retry Job Test',
            'slug' => 'restaurante-retry-job-test',
            'vertical' => 'restaurant',
        ]);

        BusinessContext::set($this->business);

        $this->integration = BusinessIntegration::create([
            'business_id' => $this->business->id,
            'provider' => 'doblefilo',
            'base_url' => 'https://doblefilo.vercel.app',
            'api_key' => 'secret_key_retry',
            'enabled' => true,
        ]);

        $this->item = InventoryItem::create([
            'business_id' => $this->business->id,
            'name' => 'Lomo Fino',
            'type' => 'supply',
            'stock' => 10,
            'attributes' => ['unit' => 'kg', 'sku' => 'PRD-LOMO'],
        ]);

        IntegrationMapping::create([
            'business_id' => $this->business->id,
            'provider' => 'doblefilo',
            'external_id' => 'PRD-LOMO',
            'external_uid' => 'mongo_lomo_123',
            'inventory_item_id' => $this->item->id,
        ]);

        $this->transaction = InventoryTransaction::create([
            'business_id' => $this->business->id,
            'inventory_item_id' => $this->item->id,
            'type' => 'adjust',
            'quantity' => -2,
            'balance_after' => 8,
            'reason' => 'Consumo vía Telegram',
            'mirror_status' => 'failed',
            'mirror_payload' => [
                'type' => 'output',
                'quantity' => 2,
                'unit' => 'kg',
                'location' => 'kitchen',
                'notes' => 'Registrado por Juan vía Telegram Ualdo',
            ],
        ]);
    }

    public function test_retry_job_pushes_movement_and_marks_status_synced(): void
    {
        Http::fake([
            'https://doblefilo.vercel.app/api/inventory/movements' => Http::response([
                'success' => true,
                'movementId' => 'mov_retry_555',
            ], 201),
        ]);

        $job = new RetryDoblefiloPushJob($this->transaction);
        $job->handle();

        $this->assertEquals('synced', $this->transaction->fresh()->mirror_status);

        Http::assertSent(function ($request) {
            return $request['productId'] === 'mongo_lomo_123'
                && $request['movementType'] === 'output'
                && $request['quantity'] == 2
                && $request['fromLocation'] === 'kitchen';
        });
    }
}
