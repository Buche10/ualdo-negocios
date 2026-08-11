<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Business;
use App\Models\Contact;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\User;
use App\Services\AdminTools\AdminWriteTools;
use App\Services\BusinessContext;
use App\Services\HealthSkillsService;
use App\Services\InventoryService;
use Carbon\Carbon;
use Cknow\Money\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryLedgerTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create([
            'name' => 'Consultorio Ledger Test',
            'slug' => 'consultorio-ledger-test',
            'vertical' => 'health',
            'whatsapp_phone_number_id' => 'phone_ledger_123',
        ]);

        BusinessContext::set($this->business);
    }

    public function test_adjust_stock_creates_ledger_transaction_and_updates_balance(): void
    {
        $item = InventoryItem::create([
            'business_id' => $this->business->id,
            'name' => 'Guantes de Nitrilo',
            'type' => 'supply',
            'stock' => 50,
            'min_stock' => 10,
            'price' => Money::USD(500),
        ]);

        $service = app(InventoryService::class);
        $user = User::factory()->create(['business_id' => $this->business->id]);

        $tx = $service->adjustStock(
            item: $item,
            delta: -10,
            type: 'out',
            reason: 'Uso en consultas',
            user: $user
        );

        $this->assertEquals(-10, $tx->quantity);
        $this->assertEquals(40, $tx->balance_after);
        $this->assertEquals('out', $tx->type);
        $this->assertEquals('Uso en consultas', $tx->reason);
        $this->assertEquals($item->id, $tx->inventory_item_id);
        $this->assertEquals($this->business->id, $tx->business_id);

        $this->assertDatabaseHas('inventory_items', [
            'id' => $item->id,
            'stock' => 40,
        ]);
        $this->assertDatabaseHas('inventory_transactions', [
            'id' => $tx->id,
            'quantity' => -10,
            'balance_after' => 40,
        ]);
    }

    public function test_scheduling_appointment_deducts_supplies_and_binds_appointment_morph_source(): void
    {
        $contact = Contact::create(['phone_number' => '593999111222', 'name' => 'Ana Morales']);

        $serviceItem = InventoryItem::create([
            'business_id' => $this->business->id,
            'name' => 'Profilaxis Dental',
            'type' => 'service',
            'price' => Money::USD(3000),
            'stock' => 0,
            'min_stock' => 0,
        ]);

        $supplyItem = InventoryItem::create([
            'business_id' => $this->business->id,
            'name' => 'Pasta Profiláctica',
            'type' => 'supply',
            'stock' => 20,
            'min_stock' => 5,
            'price' => Money::USD(200),
        ]);

        $serviceItem->requiredSupplies()->attach($supplyItem->id, ['quantity_required' => 2]);

        $healthService = new HealthSkillsService;
        $tools = $healthService->getTools($contact);
        $scheduleTool = collect($tools)->firstWhere(fn ($t) => $t->name() === 'schedule_appointment');

        $tz = 'America/Guayaquil';
        $tomorrow = Carbon::tomorrow($tz)->format('Y-m-d');

        $resJson = $scheduleTool->handle("{$tomorrow} 10:00", 'Profilaxis Dental');
        $res = json_decode($resJson, true);

        $this->assertEquals('success', $res['status']);

        $appointment = Appointment::where('title', 'Profilaxis Dental')->first();
        $this->assertNotNull($appointment);

        $this->assertDatabaseHas('inventory_items', [
            'id' => $supplyItem->id,
            'stock' => 18,
        ]);

        $tx = InventoryTransaction::where('inventory_item_id', $supplyItem->id)->first();
        $this->assertNotNull($tx);
        $this->assertEquals(-2, $tx->quantity);
        $this->assertEquals(18, $tx->balance_after);
        $this->assertEquals(Appointment::class, $tx->source_type);
        $this->assertEquals($appointment->id, $tx->source_id);
    }

    public function test_admin_restock_tool_updates_stock_and_logs_restock_transaction(): void
    {
        $user = User::factory()->create(['business_id' => $this->business->id, 'role' => 'owner']);
        if (method_exists($user, 'assignRole')) {
            $user->assignRole('owner');
        }

        $item = InventoryItem::create([
            'business_id' => $this->business->id,
            'name' => 'Anestesia Local',
            'type' => 'supply',
            'stock' => 5,
            'min_stock' => 2,
            'price' => Money::USD(1500),
        ]);

        $writeTools = app(AdminWriteTools::class);
        $tools = $writeTools->getTools($user);
        $restockTool = collect($tools)->firstWhere(fn ($t) => $t->name() === 'restock_item');

        $resJson = $restockTool->handle('Anestesia Local', 20, 'FarmaCorp');
        $res = json_decode($resJson, true);

        $this->assertEquals('success', $res['status']);
        $this->assertEquals(25, $res['new_stock']);

        $item->refresh();
        $this->assertEquals(25, $item->stock);
        $this->assertEquals('FarmaCorp', $item->supplier);

        $tx = InventoryTransaction::where('inventory_item_id', $item->id)->first();
        $this->assertNotNull($tx);
        $this->assertEquals('restock', $tx->type);
        $this->assertEquals(20, $tx->quantity);
        $this->assertEquals(25, $tx->balance_after);
    }
}
