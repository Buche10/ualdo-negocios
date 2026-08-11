<?php

namespace Tests\Feature;

use App\Jobs\ProcessWhatsAppWebhookJob;
use App\Models\Appointment;
use App\Models\Business;
use App\Models\Contact;
use App\Models\Doctor;
use App\Models\Message;
use App\Services\BusinessContext;
use App\Services\GoogleCalendarService;
use App\Services\HealthSkillsService;
use App\Services\UaldoManagerService;
use App\Services\WhatsAppService;
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

        $business = Business::first() ?? Business::create([
            'name' => 'Consultorio Salud Principal',
            'slug' => 'consultorio-salud-principal',
            'vertical' => 'health',
            'whatsapp_phone_number_id' => 'default_phone_id',
        ]);

        BusinessContext::set($business);
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

    public function test_schedule_appointment_allows_parallel_appointments_with_different_doctors()
    {
        $business = BusinessContext::get();
        $docA = Doctor::create(['business_id' => $business->id, 'name' => 'Dr. Alpha', 'is_active' => true]);
        $docB = Doctor::create(['business_id' => $business->id, 'name' => 'Dr. Beta', 'is_active' => true]);

        $contact = Contact::create(['phone_number' => '593999888777', 'name' => 'Carlos Andrade']);
        $service = new HealthSkillsService;
        $tools = $service->getTools($contact);
        $scheduleTool = collect($tools)->firstWhere(fn ($t) => $t->name() === 'schedule_appointment');

        $tz = 'America/Guayaquil';
        $today = Carbon::tomorrow($tz)->format('Y-m-d');

        // Agendar con Dr. Alpha a las 10:00
        $res1Json = $scheduleTool->handle("{$today} 10:00", 'Consulta Alpha', 'Dr. Alpha');
        $this->assertEquals('success', json_decode($res1Json, true)['status']);

        // Agendar con Dr. Beta a las 10:00 (mismo horario, distinto doctor) -> debe permitirse
        $res2Json = $scheduleTool->handle("{$today} 10:00", 'Consulta Beta', 'Dr. Beta');
        $this->assertEquals('success', json_decode($res2Json, true)['status']);
    }

    public function test_schedule_appointment_rejects_unknown_doctor_name()
    {
        $contact = Contact::create(['phone_number' => '593999888777', 'name' => 'Roberto Solis']);
        $service = new HealthSkillsService;
        $scheduleTool = collect($service->getTools($contact))->firstWhere(fn ($t) => $t->name() === 'schedule_appointment');

        $tz = 'America/Guayaquil';
        $today = Carbon::tomorrow($tz)->format('Y-m-d');

        $resJson = $scheduleTool->handle("{$today} 11:00", 'Consulta General', 'Dr. Inexistente Fantasma');
        $res = json_decode($resJson, true);

        $this->assertEquals('error', $res['status']);
        $this->assertStringContainsString('No se encontró al especialista', $res['message']);
    }

    public function test_schedule_appointment_rejects_past_datetime()
    {
        $contact = Contact::create(['phone_number' => '593999888777', 'name' => 'Lucia Paz']);
        $service = new HealthSkillsService;
        $scheduleTool = collect($service->getTools($contact))->firstWhere(fn ($t) => $t->name() === 'schedule_appointment');

        $pastDate = Carbon::now('America/Guayaquil')->subDay()->format('Y-m-d H:i');
        $resJson = $scheduleTool->handle($pastDate, 'Consulta Pasada');
        $res = json_decode($resJson, true);

        $this->assertEquals('error', $res['status']);
        $this->assertStringContainsString('pasadas', $res['message']);
    }

    public function test_schedule_appointment_rejects_outside_business_hours()
    {
        $contact = Contact::create(['phone_number' => '593999888777', 'name' => 'Diego Rivas']);
        $service = new HealthSkillsService;
        $scheduleTool = collect($service->getTools($contact))->firstWhere(fn ($t) => $t->name() === 'schedule_appointment');

        $tz = 'America/Guayaquil';
        $today = Carbon::tomorrow($tz)->format('Y-m-d');

        // Intentar agendar a las 03:00 (fuera de horario comercial 09:00 - 18:00)
        $resJson = $scheduleTool->handle("{$today} 03:00", 'Consulta Nocturna');
        $res = json_decode($resJson, true);

        $this->assertEquals('error', $res['status']);
        $this->assertStringContainsString('fuera de nuestro horario', $res['message']);
    }

    public function test_outgoing_whatsapp_service_uses_tenant_credentials()
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['messaging_product' => 'whatsapp'], 200),
        ]);

        $tenantBusiness = Business::create([
            'name' => 'Consultorio Beta',
            'slug' => 'consultorio-beta',
            'vertical' => 'health',
            'whatsapp_phone_number_id' => 'phone_id_tenant_beta_999',
            'settings' => ['whatsapp_access_token' => 'token_tenant_beta_xyz'],
        ]);

        BusinessContext::set($tenantBusiness);

        $ws = new WhatsAppService;
        $ws->sendText('5939911122233', 'Hola desde Tenant Beta');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/v20.0/phone_id_tenant_beta_999/messages')
                && $request->hasHeader('Authorization', 'Bearer token_tenant_beta_xyz');
        });
    }

    public function test_google_calendar_service_parses_json_credentials()
    {
        config([
            'services.google.service_account_json' => json_encode([
                'client_email' => 'test-sa@project.iam.gserviceaccount.com',
                'private_key' => '-----BEGIN PRIVATE KEY-----\nMIIEvgIBADANBgkqhkiG9w0BAQEFAASCBKgwggSkAgEAAoIBAQC...\n-----END PRIVATE KEY-----\n',
            ]),
        ]);

        $gcs = new GoogleCalendarService;
        $ref = new \ReflectionClass($gcs);
        $prop = $ref->getProperty('serviceAccount');
        $prop->setAccessible(true);
        $sa = $prop->getValue($gcs);

        $this->assertEquals('test-sa@project.iam.gserviceaccount.com', $sa['client_email']);
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

    public function test_cancel_appointment_tool_frees_slot_and_deletes_google_calendar_event()
    {
        config([
            'services.google.service_account' => [
                'client_email' => 'test-sa@project.iam.gserviceaccount.com',
                'private_key' => '-----BEGIN PRIVATE KEY-----\nMIIEvgIBADANBgkqhkiG9w0BAQEFAASCBKgwggSkAgEAAoIBAQC...\n-----END PRIVATE KEY-----\n',
            ],
        ]);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'mock_token'], 200),
            'www.googleapis.com/calendar/v3/calendars/*/events/*' => Http::response([], 204),
        ]);

        $contact = Contact::create(['phone_number' => '593999888777', 'name' => 'Luis Vera']);
        $tz = 'America/Guayaquil';
        $day = Carbon::tomorrow($tz)->format('Y-m-d');

        $app = Appointment::create([
            'contact_id' => $contact->id,
            'title' => 'Consulta Odontológica',
            'start_time' => Carbon::parse("{$day} 11:00:00", $tz),
            'end_time' => Carbon::parse("{$day} 11:45:00", $tz),
            'status' => 'scheduled',
            'google_event_id' => 'gcal_event_12345',
        ]);

        $service = new HealthSkillsService;
        $cancelTool = collect($service->getTools($contact))->firstWhere(fn ($t) => $t->name() === 'cancel_appointment');

        $cancelJson = $cancelTool->handle(null);
        $cancel = json_decode($cancelJson, true);

        $this->assertEquals('success', $cancel['status']);
        $this->assertEquals('cancelled', $app->fresh()->status);

        Http::assertSent(function ($request) {
            return $request->method() === 'DELETE' && str_contains($request->url(), 'gcal_event_12345');
        });
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
        $contact = Contact::create(['phone_number' => '593999000111', 'name' => 'Ana Torres']);
        Message::create([
            'contact_id' => $contact->id,
            'role' => 'user',
            'content' => 'Hola, quiero una cita',
            'wa_id' => 'wamid.DUPLICATE',
        ]);

        $service = app(UaldoManagerService::class);

        $reply = $service->processIncomingMessage('593999000111', 'Hola, quiero una cita', 'whatsapp', 'wamid.DUPLICATE');

        $this->assertSame('', $reply);
        $this->assertEquals(1, Message::where('wa_id', 'wamid.DUPLICATE')->count());
    }

    public function test_webhook_validates_x_hub_signature()
    {
        config(['services.whatsapp.app_secret' => 'super_secret_key']);

        $payload = json_encode(['object' => 'whatsapp_business_account']);

        $response = $this->call('POST', '/api/whatsapp/webhook', [], [], [], [
            'HTTP_X-Hub-Signature-256' => 'sha256=invalid_signature',
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $response->assertStatus(401);

        $validHash = hash_hmac('sha256', $payload, 'super_secret_key');
        $responseValid = $this->call('POST', '/api/whatsapp/webhook', [], [], [], [
            'HTTP_X-Hub-Signature-256' => "sha256={$validHash}",
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $responseValid->assertStatus(200);
    }
}
