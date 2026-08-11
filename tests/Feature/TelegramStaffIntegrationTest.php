<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\StaffMemory;
use App\Models\Task;
use App\Models\User;
use App\Services\BusinessContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramStaffIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telegram.bot_token' => 'test_telegram_bot_token_123']);
    }

    public function test_user_can_generate_connect_code()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/channels/telegram/code');

        $response->assertRedirect();
        $response->assertSessionHas('connect_code');

        $code = session('connect_code');
        $this->assertEquals(6, strlen($code));
        $this->assertEquals($user->id, Cache::get("telegram_connect_{$code}"));
    }

    public function test_webhook_rejects_without_secret_token()
    {
        config(['services.telegram.secret_token' => 'my_secret_token_123']);

        $response = $this->postJson('/api/telegram/webhook', [
            'update_id' => 1001,
            'message' => ['text' => '/connect 123456', 'chat' => ['id' => 999]],
        ]);

        $response->assertStatus(401);
    }

    public function test_member_connects_binds_chat_id_to_user()
    {
        config(['services.telegram.secret_token' => 'my_secret_token_123']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $business = Business::create(['name' => 'Consultorio Alpha', 'slug' => 'alpha', 'vertical' => 'health']);
        $user = User::factory()->create(['business_id' => $business->id, 'telegram_chat_id' => null]);

        // Simular código generado
        Cache::put('telegram_connect_ABC123', $user->id, 900);

        $payload = [
            'update_id' => 2001,
            'message' => [
                'chat' => ['id' => 555666777],
                'text' => '/connect ABC123',
            ],
        ];

        $response = $this->postJson('/api/telegram/webhook', $payload, [
            'X-Telegram-Bot-Api-Secret-Token' => 'my_secret_token_123',
        ]);

        $response->assertStatus(200);
        $response->assertJson(['status' => 'connected']);

        $user->refresh();
        $this->assertEquals('555666777', $user->telegram_chat_id);
    }

    public function test_rate_limit_brute_force_on_connect_code()
    {
        config(['services.telegram.secret_token' => null]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $chatId = 888999111;

        for ($i = 1; $i <= 5; $i++) {
            $this->postJson('/api/telegram/webhook', [
                'update_id' => 4000 + $i,
                'message' => ['chat' => ['id' => $chatId], 'text' => "/connect WRONG{$i}"],
            ]);
        }

        $response = $this->postJson('/api/telegram/webhook', [
            'update_id' => 4010,
            'message' => ['chat' => ['id' => $chatId], 'text' => '/connect TEST99'],
        ]);

        $response->assertStatus(429);
        $response->assertJson(['status' => 'rate_limited_chat']);
    }

    public function test_staff_can_assign_task_to_another_member_with_proactive_dm()
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $business = Business::create(['name' => 'Consultorio Multi', 'slug' => 'multi', 'vertical' => 'health']);
        $owner = User::factory()->create([
            'business_id' => $business->id,
            'role' => 'owner',
            'telegram_chat_id' => '111222333',
        ]);
        $doctor = User::factory()->create([
            'business_id' => $business->id,
            'name' => 'Dr. Carlos Reyes',
            'role' => 'doctor',
            'telegram_chat_id' => '444555666',
        ]);

        $payload = [
            'update_id' => 5001,
            'message' => [
                'chat' => ['id' => 111222333],
                'text' => '/tarea para Carlos Confirmar historia clínica',
            ],
        ];

        $response = $this->postJson('/api/telegram/webhook', $payload);
        $response->assertStatus(200);

        $task = BusinessContext::runInContext($business, fn () => Task::where('title', 'Confirmar historia clínica')->first());
        $this->assertNotNull($task);
        $this->assertEquals($doctor->id, $task->assigned_to);

        // Verificar envío de DM proactivo al Dr. Carlos
        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/sendMessage')
                && $request['chat_id'] === '444555666'
                && str_contains($request['text'], 'Nueva tarea asignada por');
        });
    }

    public function test_auto_routing_task_by_role_keyword()
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $business = Business::create(['name' => 'Consultorio Auto', 'slug' => 'auto', 'vertical' => 'health']);
        $doctor = User::factory()->create([
            'business_id' => $business->id,
            'role' => 'doctor',
            'telegram_chat_id' => '100200',
        ]);
        $receptionist = User::factory()->create([
            'business_id' => $business->id,
            'name' => 'Ana Recepcion',
            'role' => 'receptionist',
            'telegram_chat_id' => '300400',
        ]);

        $payload = [
            'update_id' => 5002,
            'message' => [
                'chat' => ['id' => 100200],
                'text' => '/tarea Revisar stock de alcohol y insumos',
            ],
        ];

        $this->postJson('/api/telegram/webhook', $payload);

        $task = BusinessContext::runInContext($business, fn () => Task::where('title', 'Revisar stock de alcohol y insumos')->first());
        $this->assertNotNull($task);
        // Debe rutar automáticamente a la recepcionista
        $this->assertEquals($receptionist->id, $task->assigned_to);
    }

    public function test_staff_can_recall_saved_memories()
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $business = Business::create(['name' => 'Consultorio Recall', 'slug' => 'recall', 'vertical' => 'health']);
        $user = User::factory()->create([
            'business_id' => $business->id,
            'telegram_chat_id' => '777888999',
        ]);

        BusinessContext::runInContext($business, fn () => StaffMemory::create([
            'business_id' => $business->id,
            'user_id' => $user->id,
            'content' => 'El proveedor de insumos atiende los jueves',
            'type' => 'note',
        ]));

        $payload = [
            'update_id' => 6001,
            'message' => [
                'chat' => ['id' => 777888999],
                'text' => '¿Qué te dije?',
            ],
        ];

        $response = $this->postJson('/api/telegram/webhook', $payload);
        $response->assertStatus(200);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/sendMessage')
                && str_contains($request['text'], 'El proveedor de insumos atiende los jueves');
        });
    }

    public function test_scheduled_reminders_command_sends_notifications()
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $business = Business::create(['name' => 'Consultorio Reminders', 'slug' => 'rem', 'vertical' => 'health']);
        $user = User::factory()->create([
            'business_id' => $business->id,
            'telegram_chat_id' => '555999',
        ]);

        // Tarea con recordatorio pendiente
        BusinessContext::runInContext($business, fn () => Task::create([
            'business_id' => $business->id,
            'assigned_to' => $user->id,
            'title' => 'Llamar a paciente urgente',
            'due_at' => now()->subMinute(),
            'status' => 'pending',
        ]));

        // Memoria agendada vencida
        BusinessContext::runInContext($business, fn () => StaffMemory::create([
            'business_id' => $business->id,
            'user_id' => $user->id,
            'content' => 'Aviso de mantenimiento de sillón dental',
            'type' => 'reminder',
            'remind_at' => now()->subMinute(),
        ]));

        Artisan::call('app:send-task-reminders');

        Http::assertSent(function ($request) {
            return str_contains($request['text'], 'Recordatorio de Tarea Pendiente')
                || str_contains($request['text'], 'Aviso de mantenimiento');
        });
    }

    public function test_business_context_is_cleared_after_webhook()
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $business = Business::create(['name' => 'Consultorio Context', 'slug' => 'ctx', 'vertical' => 'health']);
        $user = User::factory()->create([
            'business_id' => $business->id,
            'telegram_chat_id' => '123123',
        ]);

        $this->postJson('/api/telegram/webhook', [
            'update_id' => 9001,
            'message' => ['chat' => ['id' => 123123], 'text' => '/mis_tareas'],
        ]);

        // El contexto debe quedar nulo tras finalizar el handler
        $this->assertNull(BusinessContext::get());
    }
}
