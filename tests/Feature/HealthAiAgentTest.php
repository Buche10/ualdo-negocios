<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Contact;
use App\Models\InventoryItem;
use App\Services\HealthSkillsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HealthAiAgentTest extends TestCase
{
    use RefreshDatabase;

    public function test_check_availability_tool_returns_available_slots()
    {
        $contact = Contact::create(['phone_number' => '593999888777', 'name' => 'Juan Perez']);

        // Crear una cita agendada hoy a las 10:00
        $today = Carbon::today()->format('Y-m-d');
        Appointment::create([
            'contact_id' => $contact->id,
            'title' => 'Consulta Odontológica',
            'start_time' => Carbon::parse("{$today} 10:00:00"),
            'end_time' => Carbon::parse("{$today} 10:45:00"),
            'status' => 'scheduled'
        ]);

        $service = new HealthSkillsService();
        $tools = $service->getTools($contact);
        $checkTool = collect($tools)->firstWhere(fn($t) => $t->name() === 'check_availability');

        $resultJson = $checkTool->handle($today);
        $result = json_decode($resultJson, true);

        $this->assertEquals('success', $result['status']);
        $this->assertContains('10:00', $result['booked_times']);
    }

    public function test_schedule_appointment_tool_enforces_anti_double_booking()
    {
        $contact = Contact::create(['phone_number' => '593999888777', 'name' => 'Maria Gomez']);

        $service = new HealthSkillsService();
        $tools = $service->getTools($contact);
        $scheduleTool = collect($tools)->firstWhere(fn($t) => $t->name() === 'schedule_appointment');

        $slot = Carbon::tomorrow()->format('Y-m-d 11:00');

        // 1. Agendar primera cita
        $res1Json = $scheduleTool->handle($slot, 'Limpieza Dental');
        $res1 = json_decode($res1Json, true);
        $this->assertEquals('success', $res1['status']);

        // 2. Intentar agendar en la misma hora -> debe fallar por el guard
        $res2Json = $scheduleTool->handle($slot, 'Consulta General');
        $res2 = json_decode($res2Json, true);

        $this->assertEquals('error', $res2['status']);
        $this->assertStringContainsString('ya está ocupada', $res2['message']);
    }

    public function test_search_services_tool_returns_consultorio_inventory()
    {
        $contact = Contact::create(['phone_number' => '593999888777', 'name' => 'Carlos']);

        InventoryItem::create([
            'name' => 'Blanqueamiento Dental LED',
            'description' => 'Tratamiento estético de aclaramiento dental',
            'type' => 'service',
            'price' => 120.00,
            'stock' => 999
        ]);

        $service = new HealthSkillsService();
        $tools = $service->getTools($contact);
        $searchTool = collect($tools)->firstWhere(fn($t) => $t->name() === 'search_services');

        $resJson = $searchTool->handle('blanqueamiento');
        $res = json_decode($resJson, true);

        $this->assertEquals('success', $res['status']);
        $this->assertCount(1, $res['services']);
        $this->assertEquals('Blanqueamiento Dental LED', $res['services'][0]['name']);
    }

    public function test_transfer_to_human_tool_pauses_bot_for_24_hours()
    {
        $contact = Contact::create(['phone_number' => '593999888777', 'name' => 'Ana']);

        $service = new HealthSkillsService();
        $tools = $service->getTools($contact);
        $transferTool = collect($tools)->firstWhere(fn($t) => $t->name() === 'transfer_to_human');

        $resJson = $transferTool->handle('Quiero hablar con una recepcionista');
        $res = json_decode($resJson, true);

        $this->assertEquals('success', $res['status']);
        $contact->refresh();
        $this->assertNotNull($contact->bot_paused_until);
        $this->assertTrue(Carbon::parse($contact->bot_paused_until)->isFuture());
    }
}
