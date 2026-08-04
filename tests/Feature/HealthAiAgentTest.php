<?php

namespace Tests\Feature;

use App\Jobs\ProcessWhatsAppWebhookJob;
use App\Models\Appointment;
use App\Models\Contact;
use App\Models\Message;
use App\Services\HealthSkillsService;
use App\Services\UaldoManagerService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class HealthAiAgentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $business = \App\Models\Business::first() ?? \App\Models\Business::create([
            'name' => 'Consultorio Salud Principal',
            'slug' => 'consultorio-salud-principal',
            'vertical' => 'health',
            'whatsapp_phone_number_id' => 'default_phone_id',
        ]);

        \App\Services\BusinessContext::set($business);
    }

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
            'status' => 'scheduled',
        ]);

        $service = new HealthSkillsService;
        $tools = $service->getTools($contact);
        $checkTool = collect($tools)->firstWhere(fn ($t) => $t->name() === 'check_availability');

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

        $service = new HealthSkillsService;
        $tools = $service->getTools($contact);
        $scheduleTool = collect($tools)->firstWhere(fn ($t) => $t->name() === 'schedule_appointment');

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
                                        'text' => ['body' => 'Hola, deseo agendar una cita'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $response = $this->postJson('/api/whatsapp/webhook', $payload);

        $response->assertStatus(200);
        Queue::assertPushed(ProcessWhatsAppWebhookJob::class);
    }

    public function test_cancel_appointment_tool_frees_the_slot()
    {
        $contact = Contact::create(['phone_number' => '593999888777', 'name' => 'Luis Vera']);
        $service = new HealthSkillsService;
        $tools = $service->getTools($contact);
        $scheduleTool = collect($tools)->firstWhere(fn ($t) => $t->name() === 'schedule_appointment');
        $cancelTool = collect($tools)->firstWhere(fn ($t) => $t->name() === 'cancel_appointment');

        $tz = 'America/Guayaquil';
        $day = Carbon::tomorrow($tz)->format('Y-m-d');

        // Agendar y luego cancelar (única cita, sin pasar datetime)
        $scheduleTool->handle("{$day} 11:00", 'Consulta General');
        $cancelJson = $cancelTool->handle(null);
        $cancel = json_decode($cancelJson, true);
        $this->assertEquals('success', $cancel['status']);

        // El horario queda libre: se puede volver a agendar sin solaparse
        $reJson = $scheduleTool->handle("{$day} 11:00", 'Otra Consulta');
        $this->assertEquals('success', json_decode($reJson, true)['status']);
    }

    public function test_reschedule_appointment_tool_moves_the_slot()
    {
        $contact = Contact::create(['phone_number' => '593999888777', 'name' => 'Sofia Luna']);
        $service = new HealthSkillsService;
        $tools = $service->getTools($contact);
        $scheduleTool = collect($tools)->firstWhere(fn ($t) => $t->name() === 'schedule_appointment');
        $rescheduleTool = collect($tools)->firstWhere(fn ($t) => $t->name() === 'reschedule_appointment');

        $tz = 'America/Guayaquil';
        $day = Carbon::tomorrow($tz)->format('Y-m-d');

        $scheduleTool->handle("{$day} 14:00", 'Limpieza Dental');
        $resJson = $rescheduleTool->handle("{$day} 16:00", null);
        $res = json_decode($resJson, true);

        $this->assertEquals('success', $res['status']);
        $this->assertDatabaseHas('appointments', [
            'contact_id' => $contact->id,
            'start_time' => Carbon::parse("{$day} 16:00", $tz)->setTimezone(config('app.timezone')),
        ]);
    }

    public function test_transfer_to_human_pauses_bot_and_notifies_staff_via_telegram()
    {
        config([
            'services.telegram.bot_token' => 'test-bot-token',
            'services.telegram.chat_id' => '123456',
        ]);
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        $contact = Contact::create(['phone_number' => '593999888777', 'name' => 'Pedro Ramos']);
        $service = new HealthSkillsService;
        $transferTool = collect($service->getTools($contact))
            ->firstWhere(fn ($t) => $t->name() === 'transfer_to_human');

        $result = json_decode($transferTool->handle('Dolor intenso, quiere hablar con el doctor'), true);

        $this->assertEquals('success', $result['status']);
        $this->assertNotNull($contact->fresh()->bot_paused_until);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'api.telegram.org/bottest-bot-token/sendMessage')
                && $request['chat_id'] === '123456'
                && str_contains($request['text'], '593999888777');
        });
    }

    public function test_duplicate_wa_id_is_not_processed_twice()
    {
        // Simula que Meta ya entregó y procesamos este mensaje (mismo wa_id).
        $contact = Contact::create(['phone_number' => '593999000111', 'name' => 'Ana Torres']);
        Message::create([
            'contact_id' => $contact->id,
            'role' => 'user',
            'content' => 'Hola, quiero una cita',
            'wa_id' => 'wamid.DUPLICATE',
        ]);

        $service = app(UaldoManagerService::class);

        // Reintento de Meta con el mismo wa_id: debe ignorarse sin llamar a la IA ni duplicar.
        $reply = $service->processIncomingMessage('593999000111', 'Hola, quiero una cita', 'whatsapp', 'wamid.DUPLICATE');

        $this->assertSame('', $reply);
        $this->assertEquals(1, Message::where('wa_id', 'wamid.DUPLICATE')->count());
    }

    public function test_webhook_validates_x_hub_signature()
    {
        config(['services.whatsapp.app_secret' => 'super_secret_key']);

        $payload = json_encode(['object' => 'whatsapp_business_account']);

        // Peticion sin firma valida -> 401 Unauthorized
        $response = $this->call('POST', '/api/whatsapp/webhook', [], [], [], [
            'HTTP_X-Hub-Signature-256' => 'sha256=invalid_signature',
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $response->assertStatus(401);

        // Peticion con firma HMAC SHA256 correcta -> 200 OK
        $validHash = hash_hmac('sha256', $payload, 'super_secret_key');
        $responseValid = $this->call('POST', '/api/whatsapp/webhook', [], [], [], [
            'HTTP_X-Hub-Signature-256' => "sha256={$validHash}",
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $responseValid->assertStatus(200);
    }
}
