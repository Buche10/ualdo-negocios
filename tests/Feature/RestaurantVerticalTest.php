<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Contact;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\Order;
use App\Models\Resource;
use App\Services\BusinessContext;
use App\Skills\RestaurantSkillProvider;
use App\Skills\SkillRegistry;
use Carbon\Carbon;
use Cknow\Money\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RestaurantVerticalTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create([
            'name' => 'Bistro Gourmet',
            'slug' => 'bistro-gourmet',
            'vertical' => 'restaurant',
            'whatsapp_phone_number_id' => 'phone_resto_123',
        ]);

        BusinessContext::set($this->business);
    }

    public function test_skill_registry_resolves_restaurant_provider_for_restaurant_vertical(): void
    {
        $registry = new SkillRegistry;
        $provider = $registry->for($this->business->vertical);

        $this->assertInstanceOf(RestaurantSkillProvider::class, $provider);
        $this->assertEquals('restaurant', $provider->vertical());
    }

    public function test_restaurant_reserve_table_tool_checks_capacity_and_reserves_resource(): void
    {
        $table4 = Resource::create([
            'business_id' => $this->business->id,
            'name' => 'Mesa 4 personas',
            'type' => 'table',
            'capacity' => 4,
            'is_active' => true,
        ]);

        $contact = Contact::create(['phone_number' => '593955555555', 'name' => 'Esteban Gomez']);
        $provider = app(SkillRegistry::class)->for('restaurant');
        $tools = $provider->getTools($contact);

        $reserveTool = collect($tools)->firstWhere(fn ($t) => $t->name() === 'reserve_table');

        $tz = 'America/Guayaquil';
        $tomorrow = Carbon::tomorrow($tz)->format('Y-m-d');

        $resJson = $reserveTool->handle("{$tomorrow} 13:00", 4, 'Mesa 4 personas');
        $res = json_decode($resJson, true);

        $this->assertEquals('success', $res['status']);

        $this->assertDatabaseHas('appointments', [
            'resource_id' => $table4->id,
            'contact_id' => $contact->id,
        ]);
    }

    public function test_restaurant_place_order_tool_creates_confirmed_order_and_deducts_inventory(): void
    {
        $contact = Contact::create(['phone_number' => '593955555555', 'name' => 'Esteban Gomez']);

        $dish = InventoryItem::create([
            'business_id' => $this->business->id,
            'name' => 'Pizza Margherita',
            'type' => 'product',
            'stock' => 10,
            'min_stock' => 2,
            'price' => Money::USD(1200),
        ]);

        $provider = app(SkillRegistry::class)->for('restaurant');
        $tools = $provider->getTools($contact);

        $orderTool = collect($tools)->firstWhere(fn ($t) => $t->name() === 'place_order');

        $resJson = $orderTool->handle('Pizza Margherita', 2);
        $res = json_decode($resJson, true);

        $this->assertEquals('success', $res['status']);
        $this->assertNotNull($res['order_id']);

        $order = Order::find($res['order_id']);
        $this->assertEquals('confirmed', $order->status);
        $this->assertEquals(2400, $order->total_cents);

        $dish->refresh();
        $this->assertEquals(8, $dish->stock);

        $tx = InventoryTransaction::where('inventory_item_id', $dish->id)->first();
        $this->assertEquals(-2, $tx->quantity);
        $this->assertEquals(8, $tx->balance_after);
        $this->assertEquals('sale', $tx->type);
    }
}
