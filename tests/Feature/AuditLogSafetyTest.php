<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\InventoryItem;
use App\Services\BusinessContext;
use Cknow\Money\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class AuditLogSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_log_warning_on_missing_context_does_not_abort_model_save()
    {
        $business = Business::create([
            'name' => 'Consultorio Background Job',
            'slug' => 'consultorio-background-job',
            'vertical' => 'health',
        ]);

        // Explicitly clear BusinessContext to simulate background task without context
        BusinessContext::forget();
        $this->assertNull(BusinessContext::get());

        Log::spy();

        // Direct DB creation with explicit business_id (background process updating item)
        $item = InventoryItem::withoutGlobalScopes()->create([
            'business_id' => $business->id,
            'name' => 'Insumo Background Process',
            'stock' => 50,
            'min_stock' => 10,
            'price' => Money::USD(1250),
        ]);

        $this->assertNotNull($item->id);
        $this->assertEquals('Insumo Background Process', $item->name);

        // Verify that model saved cleanly without throwing any RuntimeException
        $this->assertDatabaseHas('inventory_items', [
            'id' => $item->id,
            'name' => 'Insumo Background Process',
        ]);
    }
}
