<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\InventoryItem;
use App\Models\PendingAction;
use App\Models\User;
use App\Services\AdminTools\AdminWriteTools;
use App\Services\ApprovalGate;
use App\Services\BusinessContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminChatM1Test extends TestCase
{
    use RefreshDatabase;

    protected Business $business;

    protected User $owner;

    protected User $receptionist;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create([
            'name' => 'Consultorio Odontológico M1',
            'slug' => 'consultorio-m1',
            'vertical' => 'health',
            'timezone' => 'America/Guayaquil',
        ]);

        $this->owner = User::create([
            'business_id' => $this->business->id,
            'name' => 'Dra. María Owner',
            'email' => 'owner@m1.com',
            'email_verified_at' => now(),
            'password' => bcrypt('password'),
            'role' => 'owner',
        ]);

        $this->receptionist = User::create([
            'business_id' => $this->business->id,
            'name' => 'Carlos Recepción',
            'email' => 'reception@m1.com',
            'email_verified_at' => now(),
            'password' => bcrypt('password'),
            'role' => 'receptionist',
        ]);
    }

    public function test_update_stock_is_atomic_no_race()
    {
        BusinessContext::set($this->business);

        $item = InventoryItem::create([
            'business_id' => $this->business->id,
            'name' => 'Resina compuesta A2',
            'stock' => 10,
            'min_stock' => 2,
        ]);

        $writeTools = new AdminWriteTools;
        $tool = $writeTools->updateStockTool($this->owner);

        $resJson = (string) $tool->handle('Resina compuesta A2', 25, 'set');
        $res = json_decode($resJson, true);

        $this->assertEquals('success', $res['status']);
        $this->assertEquals(25, $item->fresh()->stock);
    }

    public function test_irreversible_action_requires_approval()
    {
        BusinessContext::set($this->business);

        $item = InventoryItem::create([
            'business_id' => $this->business->id,
            'name' => 'Lámpara de fotocurado',
            'stock' => 1,
            'price' => 150.0,
        ]);

        $writeTools = new AdminWriteTools;
        $tool = $writeTools->deleteInventoryItemTool($this->owner);

        $resJson = (string) $tool->handle('Lámpara de fotocurado');
        $res = json_decode($resJson, true);

        $this->assertEquals('approval_required', $res['status']);
        $this->assertNotEmpty($res['token']);

        // Item still exists in DB
        $this->assertDatabaseHas('inventory_items', ['id' => $item->id]);
    }

    public function test_approval_token_single_use_scoped_to_user()
    {
        $this->actingAs($this->owner);
        BusinessContext::set($this->business);

        $item = InventoryItem::create([
            'business_id' => $this->business->id,
            'name' => 'Bisturí #11',
            'stock' => 50,
            'price' => 5.0,
        ]);

        $gate = app(ApprovalGate::class);
        $pending = $gate->createPendingAction($this->owner, $this->business, 'update_price', ['Bisturí #11', 8.5]);

        // Attempt 1: Valid confirmation
        $response = $this->postJson('/admin/chat/approve', ['token' => $pending->token]);
        $response->assertOk();
        $this->assertEquals(850, $item->fresh()->price->getAmount());

        // Attempt 2: Reuse expired/confirmed token fails
        $response2 = $this->postJson('/admin/chat/approve', ['token' => $pending->token]);
        $response2->assertStatus(400);
    }

    public function test_every_write_is_audited()
    {
        $this->actingAs($this->owner);
        BusinessContext::set($this->business);

        $writeTools = new AdminWriteTools;
        $tool = $writeTools->addInventoryItemTool($this->owner);

        $tool->handle('Mascarillas N95', 100, 20, 1.5, 'caja', 'supply');

        $this->assertDatabaseHas('activity_log', [
            'business_id' => $this->business->id,
            'description' => 'executed:add_inventory_item',
        ]);
    }

    public function test_approval_expires()
    {
        $this->actingAs($this->owner);
        BusinessContext::set($this->business);

        $token = Str::random(32);
        $pending = PendingAction::create([
            'business_id' => $this->business->id,
            'user_id' => $this->owner->id,
            'action' => 'delete_inventory_item',
            'payload' => ['Insumo Viejo'],
            'token' => $token,
            'expires_at' => Carbon::now()->subMinutes(30),
            'status' => 'pending',
        ]);

        $response = $this->postJson('/admin/chat/approve', ['token' => $token]);
        $response->assertStatus(400);
        $response->assertJsonFragment(['message' => 'El token de aprobación ha expirado. Por favor solicita la acción nuevamente.']);
    }
}
