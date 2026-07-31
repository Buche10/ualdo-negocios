<?php

namespace Tests\Feature;

use App\Http\Controllers\WhatsAppController;
use App\Jobs\ProcessWhatsAppWebhookJob;
use App\Models\Appointment;
use App\Models\Contact;
use App\Models\InventoryItem;
use App\Services\HealthSkillsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class HealthAiAgentTest extends TestCase
{
    use RefreshDatabase;

    public function test_check_availability_tool_returns_booked_intervals()
    {
        $contact = Contact::create(['phone_number' => '593999888777', 'name' => 'Juan Perez']);

        $tz = 'America/Guayaquil';
        $today = Carbon::today($tz)->format('Y-m-d');
        Appointment::create([
            'contact_id' => $contact->id,
            'title' => 'Consulta Odontológica',
            'start_time' => Carbon::parse("{$today} 10:00:00", $tz),
            'end_time' => Carbon::parse("{$today} 10:45:00", $tz),
            'status' => 'scheduled'
        ]);

        $service = new HealthSkillsService();
        $tools = $service->getTools($contact);
        $checkTool = collect($tools)->firstWhere(fn($t) => $t->name() === 'check_availability');

        $resultJson = $checkTool->handle($today);
        $result = json_decode($resultJson, true);

        $this->assertEquals('success', $result['status']);
        $this->assertCount(1, $result['booked_intervals']);
        $this->assertEquals('10:00', $result['booked_intervals'][0]['from']);
        $this->assertEquals('10:45', $result['booked_intervals'][0]['to']);
    }

    public function test_schedule_appointment_tool_enforces_time_range_overlap_guard()
    {
        $contact = Contact::create(['phone_number' => '593999888777', 'name' => 'Maria Gomez']);

        $service = new HealthSkillsService();
        $tools = $service->getTools($contact);
        $scheduleTool = collect($tools)->firstWhere(fn($t) => $t->name() === 'schedule_appointment');

        $tz = 'America/Guayaquil';
        $today = Carbon::tomorrow($tz)->format('Y-m-d');
        
        // 1. Agendar cita de 09:00 a 09:45
        $res1Json = $scheduleTool->handle("{$today} 09:00", 'Limpieza Dental');
        $res1 = json_decode($res1Json, true);
        $this->assertEquals('success', $res1['status']);

        // 2. Intentar agendar a las 09:30 (se solapa con la cita de 09:00 a 09:45) -> debe ser rechazado
        $res2Json = $scheduleTool->handle("{$today} 09:30", 'Consulta General');
        $res2 = json_decode($res2Json, true);

        $this->assertEquals('error', $res2['status']);
        $this->assertStringContainsString('solapa', $res2['message']);
    }

    public function test_webhook_dispatches_async_job_immediately()
    {
        Queue::fake();

        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [
                [
                    'changes' => [
                        [
                            'value' => [
                                'messages' => [
                                    [
                                        'from' => '593999111222',
                                        'id' => 'wamid.123',
                                        'text' => ['body' => 'Hola, deseo agendar una cita']
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            ]
        ];

        $response = $this->postJson('/api/whatsapp/webhook', $payload);

        $response->assertStatus(200);
        Queue::assertPushed(ProcessWhatsAppWebhookJob::class);
    }

    public function test_webhook_validates_x_hub_signature()
    {
        config(['services.whatsapp.app_secret' => 'super_secret_key']);

        $payload = json_encode(['object' => 'whatsapp_business_account']);

        // Peticion sin firma valida -> 401 Unauthorized
        $response = $this->call('POST', '/api/whatsapp/webhook', [], [], [], [
            'HTTP_X-Hub-Signature-256' => 'sha256=invalid_signature',
            'CONTENT_TYPE' => 'application/json'
        ], $payload);

        $response->assertStatus(401);

        // Peticion con firma HMAC SHA256 correcta -> 200 OK
        $validHash = hash_hmac('sha256', $payload, 'super_secret_key');
        $responseValid = $this->call('POST', '/api/whatsapp/webhook', [], [], [], [
            'HTTP_X-Hub-Signature-256' => "sha256={$validHash}",
            'CONTENT_TYPE' => 'application/json'
        ], $payload);

        $responseValid->assertStatus(200);
    }
}
