<?php

namespace Tests\Feature;

use App\Jobs\SyncInventoryFromDoblefiloJob;
use App\Models\Business;
use App\Models\BusinessIntegration;
use App\Models\InventoryItem;
use App\Models\SyncRun;
use App\Services\BusinessContext;
use App\Services\IntegrationSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncDoblefiloJobTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;

    protected BusinessIntegration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create([
            'name' => 'Restaurante Job Test',
            'slug' => 'restaurante-job-test',
            'vertical' => 'restaurant',
        ]);

        BusinessContext::set($this->business);

        $this->integration = BusinessIntegration::create([
            'business_id' => $this->business->id,
            'provider' => 'doblefilo',
            'base_url' => 'https://doblefilo.vercel.app',
            'api_key' => 'job_key_123',
            'enabled' => true,
        ]);
    }

    public function test_job_loads_integration_without_context_and_runs_in_context(): void
    {
        Http::fake([
            'https://doblefilo.vercel.app/api/inventory*' => Http::response([
                'success' => true,
                'data' => [
                    [
                        'code' => 'PRD-JOB-01',
                        'name' => 'Arroz Flor Especial',
                        'unit' => 'kg',
                        'inventory' => ['available' => 50],
                    ],
                ],
                'meta' => ['page' => 1, 'pages' => 1],
            ], 200),
        ]);

        // CRÍTICO: Limpiar BusinessContext para simular worker de cola en background
        BusinessContext::clear();
        $this->assertNull(BusinessContext::get());

        $job = new SyncInventoryFromDoblefiloJob($this->integration->id);
        $job->handle(app(IntegrationSyncService::class));

        // BusinessContext debe haberse limpiado al finalizar el job
        $this->assertNull(BusinessContext::get());

        $syncRun = SyncRun::withoutGlobalScopes()->where('provider', 'doblefilo')->first();
        $this->assertNotNull($syncRun);
        $this->assertEquals('success', $syncRun->status);
        $this->assertEquals(1, $syncRun->created_count);

        $item = InventoryItem::withoutGlobalScopes()->where('name', 'Arroz Flor Especial')->first();
        $this->assertNotNull($item);
        $this->assertEquals(50, $item->stock);
    }

    public function test_disabled_integration_is_skipped(): void
    {
        $this->integration->update(['enabled' => false]);

        BusinessContext::clear();

        $job = new SyncInventoryFromDoblefiloJob($this->integration->id);
        $job->handle(app(IntegrationSyncService::class));

        $syncRun = SyncRun::withoutGlobalScopes()->first();
        $this->assertNull($syncRun);
    }
}
