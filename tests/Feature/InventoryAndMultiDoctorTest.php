<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Contact;
use App\Models\Doctor;
use App\Models\InventoryItem;
use App\Services\BusinessContext;
use App\Services\HealthSkillsService;
use App\Services\PaymentService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryAndMultiDoctorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $business = Business::create([
            'name' => 'Consultorio Multidisciplinario',
            'slug' => 'consultorio-multidisciplinario',
            'vertical' => 'health',
            'whatsapp_phone_number_id' => 'test_phone_id',
        ]);

        BusinessContext::set($business);
    }

    public function test_inventory_item_low_stock_detection()
    {
        $item = InventoryItem::create([
            'name' => 'Guantes de Nitrilo',
            'type' => 'physical',
            'price' => 15.00,
            'stock' => 5,
            'min_stock' => 10,
        ]);

        $this->assertTrue($item->isLowStock());
    }

    public function test_service_supplies_deduction_on_appointment_schedule()
    {
        $supply = InventoryItem::create([
            'name' => 'Anestesia Local',
            'type' => 'physical',
            'price' => 5.00,
            'stock' => 20,
            'min_stock' => 5,
        ]);

        $serviceItem = InventoryItem::create([
            'name' => 'Extracción Dental',
            'type' => 'service',
            'price' => 80.00,
            'stock' => 0,
        ]);

        $serviceItem->requiredSupplies()->attach($supply->id, ['quantity_required' => 2]);

        $contact = Contact::create(['phone_number' => '593991112233', 'name' => 'Carlos Andrade']);
        $doctor = Doctor::create(['name' => 'Dr. Francisco Lopez', 'specialty' => 'Odontología']);

        $service = new HealthSkillsService;
        $tools = $service->getTools($contact);
        $scheduleTool = collect($tools)->firstWhere(fn ($t) => $t->name() === 'schedule_appointment');

        $tomorrow = Carbon::tomorrow()->format('Y-m-d');
        $resJson = $scheduleTool->handle("{$tomorrow} 11:00", 'Extracción Dental', $doctor->name);
        $res = json_decode($resJson, true);

        $this->assertEquals('success', $res['status']);
        $this->assertEquals(18, $supply->fresh()->stock);
    }

    public function test_payment_link_generation_tool()
    {
        $contact = Contact::create(['phone_number' => '593991112233', 'name' => 'Ana Morales']);
        $paymentService = new PaymentService;
        $res = $paymentService->generatePaymentLink($contact, 25.00, 'Abono de Limpieza Dental');

        $this->assertEquals('success', $res['status']);
        $this->assertStringContainsString('25.00', $res['message']);
        $this->assertNotEmpty($res['payment_url']);
    }
}
