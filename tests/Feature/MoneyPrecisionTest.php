<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Contact;
use App\Models\InventoryItem;
use App\Services\AdminTools\AdminReadTools;
use App\Services\BusinessContext;
use App\Services\HealthSkillsService;
use App\Services\PaymentService;
use Cknow\Money\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MoneyPrecisionTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create([
            'name' => 'Consultorio Money',
            'slug' => 'consultorio-money',
            'vertical' => 'health',
        ]);
        BusinessContext::set($this->business);
    }

    public function test_money_stored_as_integer_cents()
    {
        $item = InventoryItem::create([
            'business_id' => $this->business->id,
            'name' => 'Limpieza Dental Profunda',
            'stock' => 10,
            'price' => Money::USD(3550), // $35.50
        ]);

        // Verify Eloquent returns Money instance
        $this->assertInstanceOf(Money::class, $item->price);
        $this->assertEquals(3550, $item->price->getAmount());

        // Verify direct DB query stores integer cents (3550)
        $dbItem = DB::table('inventory_items')->where('id', $item->id)->first();
        $this->assertEquals(3550, (int) $dbItem->price);
    }

    public function test_payment_amount_has_no_float_rounding_errors()
    {
        $paymentService = app(PaymentService::class);
        $money = Money::USD(1999); // $19.99

        $result = $paymentService->generatePaymentLink(null, $money, 'Consulta Especializada');

        $this->assertEquals('success', $result['status']);
        $this->assertEquals(1999, $result['cents']);
        $this->assertEquals('19.99', $result['amount']);
        $this->assertStringContainsString('/pay/', $result['payment_url']);
    }

    public function test_characterization_prices_unchanged_after_migration()
    {
        $item1 = InventoryItem::create([
            'business_id' => $this->business->id,
            'name' => 'Servicio A',
            'price' => Money::USD(1500), // $15.00
        ]);

        $item2 = InventoryItem::create([
            'business_id' => $this->business->id,
            'name' => 'Servicio B',
            'price' => Money::USD(2575), // $25.75
        ]);

        $this->assertEquals(1500, $item1->fresh()->price->getAmount());
        $this->assertEquals(2575, $item2->fresh()->price->getAmount());
    }

    public function test_amount_is_server_side_not_from_llm()
    {
        $item = InventoryItem::create([
            'business_id' => $this->business->id,
            'name' => 'Ortodoncia Brackets',
            'price' => Money::USD(150000), // $1500.00
        ]);

        $paymentService = app(PaymentService::class);
        // Ensure server-side pricing comes from item->price (Money instance)
        $result = $paymentService->generatePaymentLink(null, $item->price, "Pago de {$item->name}");

        $this->assertEquals(150000, $result['cents']);
        $this->assertEquals('1,500.00', $result['amount']);
    }

    public function test_llm_tools_receive_formatted_prices_not_cents()
    {
        $item = InventoryItem::create([
            'business_id' => $this->business->id,
            'name' => 'Endodoncia Molar',
            'description' => 'Tratamiento de conducto',
            'type' => 'service',
            'stock' => 2,
            'min_stock' => 5,
            'price' => Money::USD(4500), // $45.00
        ]);

        // 1. Check search_services tool in HealthSkillsService
        $contact = Contact::create([
            'business_id' => $this->business->id,
            'phone_number' => '+593991234567',
            'name' => 'Paciente Test',
        ]);
        $healthService = app(HealthSkillsService::class);
        $tools = $healthService->getTools($contact);
        $searchServicesTool = collect($tools)->first(fn ($t) => $t->name() === 'search_services');

        $resultJson = $searchServicesTool->handle('Endodoncia');
        $resultData = json_decode($resultJson, true);

        $this->assertEquals('success', $resultData['status']);
        $this->assertEquals('$45.00', $resultData['services'][0]['price']);
        $this->assertEquals(45.0, $resultData['services'][0]['price_usd']);

        // 2. Check what_to_buy tool in AdminReadTools
        $adminRead = app(AdminReadTools::class);
        $whatToBuyTool = $adminRead->whatToBuyTool();
        $buyResultJson = $whatToBuyTool->handle();
        $buyResultData = json_decode($buyResultJson, true);

        $this->assertEquals('success', $buyResultData['status']);
        $this->assertEquals('$45.00', $buyResultData['items'][0]['price']);
        $this->assertEquals(45.0, $buyResultData['items'][0]['price_usd']);

        // 3. Check search_inventory tool in AdminReadTools
        $searchInventoryTool = $adminRead->searchInventoryTool();
        $invResultJson = $searchInventoryTool->handle('Endodoncia');
        $invResultData = json_decode($invResultJson, true);

        $this->assertEquals('success', $invResultData['status']);
        $this->assertEquals('$45.00', $invResultData['items'][0]['price']);
        $this->assertEquals(45.0, $invResultData['items'][0]['price_usd']);
    }

    public function test_migration_down_reverts_cents_to_decimal_correctly()
    {
        // 1. Insert direct integer cents record into database table
        DB::table('inventory_items')->insert([
            'business_id' => $this->business->id,
            'name' => 'Curación Resina',
            'stock' => 10,
            'min_stock' => 2,
            'type' => 'service',
            'price' => 4500, // 4500 cents = $45.00
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_08_05_000011_convert_prices_to_integer_cents.php');

        // 2. Execute down()
        $migration->down();

        $item = DB::table('inventory_items')->where('name', 'Curación Resina')->first();
        // Verify price was converted back from 4500 cents to decimal 45.00
        $this->assertEquals(45.00, (float) $item->price);

        // 3. Re-execute up() to restore state
        $migration->up();
        $restoredItem = DB::table('inventory_items')->where('name', 'Curación Resina')->first();
        $this->assertEquals(4500, (int) $restoredItem->price);
    }
}
