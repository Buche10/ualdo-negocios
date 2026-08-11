<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Business;
use App\Models\InventoryItem;
use App\Models\User;
use App\Services\AdminTools\AdminWriteTools;
use App\Services\AuditService;
use App\Services\BusinessContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActivityLogAuditTest extends TestCase
{
    use RefreshDatabase;

    protected Business $businessA;

    protected Business $businessB;

    protected User $userA;

    protected User $userB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->businessA = Business::create([
            'name' => 'Negocio Audit A',
            'slug' => 'negocio-audit-a',
            'vertical' => 'health',
        ]);

        $this->businessB = Business::create([
            'name' => 'Negocio Audit B',
            'slug' => 'negocio-audit-b',
            'vertical' => 'health',
        ]);

        $this->userA = User::create([
            'business_id' => $this->businessA->id,
            'name' => 'User Audit A',
            'email' => 'usera.audit@test.com',
            'email_verified_at' => now(),
            'password' => bcrypt('password'),
        ]);
        $this->userA->assignRole('owner');

        $this->userB = User::create([
            'business_id' => $this->businessB->id,
            'name' => 'User Audit B',
            'email' => 'userb.audit@test.com',
            'email_verified_at' => now(),
            'password' => bcrypt('password'),
        ]);
        $this->userB->assignRole('owner');
    }

    public function test_audit_is_scoped_per_business()
    {
        BusinessContext::set($this->businessA);

        app(AuditService::class)->log(
            action: 'executed:test_action_a',
            user: $this->userA
        );

        BusinessContext::set($this->businessB);

        app(AuditService::class)->log(
            action: 'executed:test_action_b',
            user: $this->userB
        );

        // Under Business A context, only ActivityLog for Business A is returned
        BusinessContext::set($this->businessA);
        $logsA = ActivityLog::where('description', 'like', 'executed:%')->get();
        $this->assertCount(1, $logsA);
        $this->assertEquals('executed:test_action_a', $logsA->first()->description);

        // Under Business B context, only ActivityLog for Business B is returned
        BusinessContext::set($this->businessB);
        $logsB = ActivityLog::where('description', 'like', 'executed:%')->get();
        $this->assertCount(1, $logsB);
        $this->assertEquals('executed:test_action_b', $logsB->first()->description);
    }

    public function test_causer_is_recorded_on_llm_writes()
    {
        BusinessContext::set($this->businessA);

        $writeTools = new AdminWriteTools;
        $tool = $writeTools->addInventoryItemTool($this->userA);

        $result = $tool->handle('Guantes Quirúrgicos', 50, 10, 5.0, 'caja', 'supply');
        $log = ActivityLog::withoutGlobalScopes()->where('description', 'executed:add_inventory_item')->latest()->first();
        $this->assertNotNull($log, "Resultado de tool execution: {$result}");
        $this->assertEquals('executed:add_inventory_item', $log->description);
        $this->assertEquals($this->userA->id, $log->causer_id);
        $this->assertEquals(User::class, $log->causer_type);
        $this->assertEquals($this->businessA->id, $log->business_id);
    }

    public function test_every_admin_write_creates_activity_entry()
    {
        BusinessContext::set($this->businessA);

        $item = InventoryItem::create([
            'business_id' => $this->businessA->id,
            'name' => 'Gasa Estéril',
            'stock' => 100,
            'price' => 2.50,
        ]);

        $this->assertDatabaseHas('activity_log', [
            'business_id' => $this->businessA->id,
            'subject_id' => $item->id,
            'subject_type' => InventoryItem::class,
        ]);
    }

    public function test_log_only_dirty_no_registra_cambios_vacios()
    {
        BusinessContext::set($this->businessA);

        $item = InventoryItem::create([
            'business_id' => $this->businessA->id,
            'name' => 'Jeringa 5ml',
            'stock' => 20,
            'price' => 1.00,
        ]);

        $initialCount = ActivityLog::withoutGlobalScopes()->where('subject_type', InventoryItem::class)->count();

        // Save model without changing attributes
        $item->save();

        $afterSaveCount = ActivityLog::withoutGlobalScopes()->where('subject_type', InventoryItem::class)->count();
        $this->assertEquals($initialCount, $afterSaveCount);
    }
}
