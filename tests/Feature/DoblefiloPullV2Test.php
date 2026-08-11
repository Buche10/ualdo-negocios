<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessIntegration;
use App\Models\IntegrationMapping;
use App\Services\BusinessContext;
use App\Services\IntegrationSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DoblefiloPullV2Test extends TestCase
{
    use RefreshDatabase;

    protected Business $business;

    protected BusinessIntegration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create([
            'name' => 'Restaurante Mongo Test',
            'slug' => 'restaurante-mongo-test',
            'vertical' => 'restaurant',
        ]);

        BusinessContext::set($this->business);

        $this->integration = BusinessIntegration::create([
            'business_id' => $this->business->id,
            'provider' => 'doblefilo',
            'base_url' => 'https://doblefilo.vercel.app',
            'api_key' => 'secret_key_v2',
            'enabled' => true,
        ]);
    }

    public function test_pull_saves_external_uid_mongo_id_in_mappings(): void
    {
        Http::fake([
            'https://doblefilo.vercel.app/api/inventory*' => Http::response([
                'success' => true,
                'data' => [
                    [
                        '_id' => '65a123b456c7890def123456',
                        'code' => 'PRD-MONGO-01',
                        'name' => 'Punta de Anca',
                        'unit' => 'kg',
                        'inventory' => ['available' => 12.5],
                    ],
                ],
                'meta' => ['page' => 1, 'pages' => 1],
            ], 200),
        ]);

        $service = app(IntegrationSyncService::class);
        $syncRun = $service->pull($this->integration);

        $this->assertEquals('success', $syncRun->status);

        $mapping = IntegrationMapping::where('external_id', 'PRD-MONGO-01')->first();
        $this->assertNotNull($mapping);
        $this->assertEquals('65a123b456c7890def123456', $mapping->external_uid);
    }
}
