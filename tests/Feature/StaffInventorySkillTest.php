<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\InventoryItem;
use App\Models\PendingAction;
use App\Models\User;
use App\Services\BusinessContext;
use App\Skills\StaffInventorySkillProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffInventorySkillTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;

    protected User $staff;

    protected InventoryItem $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create([
            'name' => 'Restaurante Skill Test',
            'slug' => 'restaurante-skill-test',
            'vertical' => 'restaurant',
        ]);

        BusinessContext::set($this->business);

        $this->staff = User::factory()->create([
            'business_id' => $this->business->id,
            'name' => 'Mario Cocinero',
        ]);

        $this->item = InventoryItem::create([
            'business_id' => $this->business->id,
            'name' => 'Piña Madura',
            'type' => 'supply',
            'stock' => 12,
            'attributes' => ['unit' => 'unit', 'sku' => 'PRD-PINA'],
        ]);
    }

    public function test_skill_search_inventory_item_finds_matching_product(): void
    {
        $provider = app(StaffInventorySkillProvider::class);
        $tools = $provider->getTools($this->staff);

        $searchTool = collect($tools)->first(fn ($t) => $t->name() === 'search_inventory_item');
        $this->assertNotNull($searchTool);

        $resultJson = $searchTool->handle('Piña');
        $result = json_decode($resultJson, true);

        $this->assertEquals('success', $result['status']);
        $this->assertEquals('Piña Madura', $result['items'][0]['name']);
        $this->assertEquals(12, $result['items'][0]['stock']);
    }

    public function test_skill_report_inventory_movement_creates_pending_action_for_confirmation(): void
    {
        $provider = app(StaffInventorySkillProvider::class);
        $tools = $provider->getTools($this->staff);

        $reportTool = collect($tools)->first(fn ($t) => $t->name() === 'report_inventory_movement');
        $this->assertNotNull($reportTool);

        $resultJson = $reportTool->handle('Piña Madura', 3, 'out', 'kitchen');
        $result = json_decode($resultJson, true);

        $this->assertEquals('requires_confirmation', $result['status']);
        $this->assertNotNull($result['action_id']);
        $this->assertStringContainsString('Confirmar Consumo / Salida de 3 unit de Piña Madura', $result['confirmation_prompt']);

        // Assert PendingAction created
        $action = PendingAction::find($result['action_id']);
        $this->assertNotNull($action);
        $this->assertEquals('inventory_adjustment', $action->action);
        $this->assertEquals($this->item->id, $action->payload['item_id']);
        $this->assertEquals(3, $action->payload['quantity']);
        $this->assertEquals('out', $action->payload['kind']);
    }
}
