<?php

namespace Tests\Feature;

use App\Jobs\ProcessWhatsAppWebhookJob;
use App\Models\Appointment;
use App\Models\Business;
use App\Models\Contact;
use App\Models\InventoryItem;
use App\Models\User;
use App\Services\BusinessContext;
use App\Services\HealthSkillsService;
use App\Services\UaldoManagerService;
use App\Services\WhatsAppService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MultiTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected Business $businessAlpha;

    protected Business $businessBeta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->businessAlpha = Business::create([
            'name' => 'Consultorio Odontológico Alpha',
            'slug' => 'consultorio-alpha',
            'vertical' => 'health',
            'whatsapp_phone_number_id' => 'phone_id_alpha_123',
            'whatsapp_phone_number' => '593991111111',
            'timezone' => 'America/Guayaquil',
            'business_hours_start' => '08:00',
            'business_hours_end' => '17:00',
            'slot_duration_minutes' => 30,
        ]);

        $this->businessBeta = Business::create([
            'name' => 'Clínica Estética Beta',
            'slug' => 'clinica-beta',
            'vertical' => 'health',
            'whatsapp_phone_number_id' => 'phone_id_beta_456',
            'whatsapp_phone_number' => '593992222222',
            'timezone' => 'America/Guayaquil',
            'business_hours_start' => '10:00',
            'business_hours_end' => '19:00',
            'slot_duration_minutes' => 60,
        ]);
    }

    public function test_webhook_routes_incoming_messages_to_correct_business_by_phone_id()
    {
        // 1. Webhook payload para Business Alpha
        $payloadAlpha = [
            'object' => 'whatsapp_business_account',
            'entry' => [
                [
                    'changes' => [
                        [
                            'value' => [
                                'metadata' => ['phone_number_id' => 'phone_id_alpha_123'],
                                'messages' => [
                                    [
                                        'from' => '593988000111',
                                        'id' => 'wamid.alpha_msg_1',
                                        'text' => ['body' => 'Hola, busco cita en Alpha'],
                                    ],
                                ],
                                'contacts' => [['profile' => ['name' => 'Paciente Alpha']]],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        // Ejecutar sincrónicamente el handler del Job de Webhook
        $jobAlpha = new ProcessWhatsAppWebhookJob($payloadAlpha);
        $jobAlpha->handle(app(UaldoManagerService::class), app(WhatsAppService::class));

        // Verificar que el contacto y mensaje se crearon bajo Business Alpha
        $contactAlpha = BusinessContext::runInContext($this->businessAlpha, function () {
            return Contact::where('phone_number', '593988000111')->first();
        });

        $this->assertNotNull($contactAlpha);
        $this->assertEquals($this->businessAlpha->id, $contactAlpha->business_id);
        $this->assertEquals('Paciente Alpha', $contactAlpha->name);

        // 2. Webhook payload para Business Beta
        $payloadBeta = [
            'object' => 'whatsapp_business_account',
            'entry' => [
                [
                    'changes' => [
                        [
                            'value' => [
                                'metadata' => ['phone_number_id' => 'phone_id_beta_456'],
                                'messages' => [
                                    [
                                        'from' => '593988000222',
                                        'id' => 'wamid.beta_msg_1',
                                        'text' => ['body' => 'Hola, busco cita en Beta'],
                                    ],
                                ],
                                'contacts' => [['profile' => ['name' => 'Paciente Beta']]],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $jobBeta = new ProcessWhatsAppWebhookJob($payloadBeta);
        $jobBeta->handle(app(UaldoManagerService::class), app(WhatsAppService::class));

        $contactBeta = BusinessContext::runInContext($this->businessBeta, function () {
            return Contact::where('phone_number', '593988000222')->first();
        });

        $this->assertNotNull($contactBeta);
        $this->assertEquals($this->businessBeta->id, $contactBeta->business_id);
        $this->assertEquals('Paciente Beta', $contactBeta->name);

        // Verificar aislamiento total de consultas en BD entre ambos negocios
        BusinessContext::runInContext($this->businessAlpha, function () {
            $this->assertEquals(1, Contact::count());
            $this->assertNull(Contact::where('phone_number', '593988000222')->first());
        });

        BusinessContext::runInContext($this->businessBeta, function () {
            $this->assertEquals(1, Contact::count());
            $this->assertNull(Contact::where('phone_number', '593988000111')->first());
        });
    }

    public function test_check_availability_and_scheduling_isolated_between_businesses()
    {
        $tz = 'America/Guayaquil';
        $tomorrow = Carbon::tomorrow($tz)->format('Y-m-d');

        // Agendar cita a las 10:00 en Business Alpha
        BusinessContext::runInContext($this->businessAlpha, function () use ($tomorrow) {
            $contactAlpha = Contact::create(['phone_number' => '593999111', 'name' => 'Paciente Alpha']);
            $service = new HealthSkillsService;
            $tools = $service->getTools($contactAlpha);
            $scheduleTool = collect($tools)->firstWhere(fn ($t) => $t->name() === 'schedule_appointment');

            $result = json_decode($scheduleTool->handle("{$tomorrow} 10:00", 'Profilaxis Alpha'), true);
            $this->assertEquals('success', $result['status']);
            $this->assertEquals(1, Appointment::count());
        });

        // Verificar que en Business Beta el horario de las 10:00 está 100% LIBRE y se puede agendar sin solapamiento
        BusinessContext::runInContext($this->businessBeta, function () use ($tomorrow) {
            $contactBeta = Contact::create(['phone_number' => '593999222', 'name' => 'Paciente Beta']);
            $service = new HealthSkillsService;
            $tools = $service->getTools($contactBeta);
            $checkTool = collect($tools)->firstWhere(fn ($t) => $t->name() === 'check_availability');
            $scheduleTool = collect($tools)->firstWhere(fn ($t) => $t->name() === 'schedule_appointment');

            // 1. Recomendar horarios
            $availability = json_decode($checkTool->handle($tomorrow), true);
            $this->assertEquals('success', $availability['status']);
            $this->assertEmpty($availability['booked_intervals']); // No hay citas en Beta

            // 2. Agendar a las 10:00 en Beta
            $resultBeta = json_decode($scheduleTool->handle("{$tomorrow} 10:00", 'Tratamiento Facia Beta'), true);
            $this->assertEquals('success', $resultBeta['status']);
            $this->assertEquals(1, Appointment::count());
        });

        // Validar que cada negocio registra exactamente 1 cita en su scope
        BusinessContext::runInContext($this->businessAlpha, function () {
            $this->assertEquals(1, Appointment::count());
            $this->assertEquals('Profilaxis Alpha', Appointment::first()->title);
        });

        BusinessContext::runInContext($this->businessBeta, function () {
            $this->assertEquals(1, Appointment::count());
            $this->assertEquals('Tratamiento Facia Beta', Appointment::first()->title);
        });
    }

    public function test_search_services_only_returns_inventory_of_current_business()
    {
        // Crear servicio en Business Alpha
        BusinessContext::runInContext($this->businessAlpha, function () {
            InventoryItem::create([
                'name' => 'Limpieza Dental Ultrasonido',
                'description' => 'Servicio dental Alpha',
                'price' => 45.00,
            ]);
        });

        // Crear servicio en Business Beta
        BusinessContext::runInContext($this->businessBeta, function () {
            InventoryItem::create([
                'name' => 'Limpieza Facial Profunda',
                'description' => 'Servicio estético Beta',
                'price' => 60.00,
            ]);
        });

        // Buscar servicios en Business Alpha
        BusinessContext::runInContext($this->businessAlpha, function () {
            $contactAlpha = Contact::create(['phone_number' => '593991111111', 'name' => 'Paciente Alpha']);
            $service = new HealthSkillsService;
            $tools = $service->getTools($contactAlpha);
            $searchTool = collect($tools)->firstWhere(fn ($t) => $t->name() === 'search_services');

            $res = json_decode($searchTool->handle('Limpieza'), true);
            $this->assertEquals('success', $res['status']);
            $this->assertCount(1, $res['services']);
            $this->assertEquals('Limpieza Dental Ultrasonido', $res['services'][0]['name']);
        });

        // Buscar servicios en Business Beta
        BusinessContext::runInContext($this->businessBeta, function () {
            $contactBeta = Contact::create(['phone_number' => '593992222222', 'name' => 'Paciente Beta']);
            $service = new HealthSkillsService;
            $tools = $service->getTools($contactBeta);
            $searchTool = collect($tools)->firstWhere(fn ($t) => $t->name() === 'search_services');

            $res = json_decode($searchTool->handle('Limpieza'), true);
            $this->assertEquals('success', $res['status']);
            $this->assertCount(1, $res['services']);
            $this->assertEquals('Limpieza Facial Profunda', $res['services'][0]['name']);
        });
    }

    public function test_same_phone_number_can_exist_in_two_different_businesses_without_unique_violation()
    {
        $phone = '593999999000';

        // 1. Crear paciente en Business Alpha con teléfono $phone
        $contactAlpha = BusinessContext::runInContext($this->businessAlpha, function () use ($phone) {
            return Contact::create(['phone_number' => $phone, 'name' => 'Cliente Alpha']);
        });

        // 2. Crear paciente en Business Beta con el MISMO teléfono $phone
        $contactBeta = BusinessContext::runInContext($this->businessBeta, function () use ($phone) {
            return Contact::create(['phone_number' => $phone, 'name' => 'Cliente Beta']);
        });

        $this->assertNotNull($contactAlpha);
        $this->assertNotNull($contactBeta);
        $this->assertEquals($this->businessAlpha->id, $contactAlpha->business_id);
        $this->assertEquals($this->businessBeta->id, $contactBeta->business_id);
        $this->assertNotEquals($contactAlpha->id, $contactBeta->id);
    }

    public function test_fail_closed_business_scope_returns_zero_records_when_no_context_is_active()
    {
        // Crear datos en Alpha y Beta
        BusinessContext::runInContext($this->businessAlpha, fn () => Contact::create(['phone_number' => '111', 'name' => 'Alpha']));
        BusinessContext::runInContext($this->businessBeta, fn () => Contact::create(['phone_number' => '222', 'name' => 'Beta']));

        // Limpiar contexto de negocio
        BusinessContext::clear();

        // Fail-closed: consulta sin contexto debe retornar 0 registros
        $this->assertEquals(0, Contact::count());
        $this->assertEquals(0, Appointment::count());
        $this->assertEquals(0, InventoryItem::count());

        // Acceso central explícito permite ver todos los registros
        $totalCentral = BusinessContext::runAsCentral(fn () => Contact::count());
        $this->assertEquals(2, $totalCentral);
    }

    public function test_saving_model_without_context_throws_runtime_exception()
    {
        BusinessContext::clear();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot create tenant model');

        Contact::create(['phone_number' => '593900000000', 'name' => 'Sin Contexto']);
    }

    public function test_transfer_to_human_uses_per_business_telegram_chat_id()
    {
        $this->businessAlpha->update(['telegram_chat_id' => 'alpha-telegram-chat-999']);

        config([
            'services.telegram.bot_token' => 'test-bot-token',
        ]);
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        BusinessContext::runInContext($this->businessAlpha, function () {
            $contact = Contact::create(['phone_number' => '593999888777', 'name' => 'Pedro Ramos']);
            $service = new HealthSkillsService;
            $transferTool = collect($service->getTools($contact))
                ->firstWhere(fn ($t) => $t->name() === 'transfer_to_human');

            $result = json_decode($transferTool->handle('Urgencia médica'), true);
            $this->assertEquals('success', $result['status']);

            Http::assertSent(function ($request) {
                return str_contains($request->url(), 'api.telegram.org/bottest-bot-token/sendMessage')
                    && $request['chat_id'] === 'alpha-telegram-chat-999';
            });
        });
    }

    public function test_inventory_panel_is_scoped_to_authenticated_users_business()
    {
        // Inventario propio de cada negocio
        BusinessContext::runInContext($this->businessAlpha, fn () => InventoryItem::create([
            'name' => 'Servicio Alpha', 'description' => 'Solo de Alpha', 'price' => 10.00,
        ]));
        BusinessContext::runInContext($this->businessBeta, fn () => InventoryItem::create([
            'name' => 'Servicio Beta', 'description' => 'Solo de Beta', 'price' => 20.00,
        ]));

        // Usuario del staff de Alpha: el middleware debe resolver su negocio y aislar el panel
        $userAlpha = User::factory()->create(['business_id' => $this->businessAlpha->id]);

        $response = $this->actingAs($userAlpha)->get('/inventory');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Inventory/Index')
            ->has('items', 1)
            ->where('items.0.name', 'Servicio Alpha')
        );
    }
}
