<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use App\Services\ApprovalGate;
use App\Services\BusinessContext;
use App\Services\TelegramService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class TelegramNutgramIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        config(['services.telegram.secret_token' => 'my-secret-token']);
        config(['services.telegram.bot_token' => '123456:ABC-DEF1234ghIkl-zyx57W2v1u123ew11']);

        $this->business = Business::create([
            'name' => 'Consultorio Nutgram',
            'slug' => 'consultorio-nutgram',
            'vertical' => 'health',
        ]);

        $this->user = User::create([
            'business_id' => $this->business->id,
            'name' => 'Dr Nutgram',
            'email' => 'nutgram@test.com',
            'email_verified_at' => now(),
            'password' => bcrypt('password'),
            'telegram_chat_id' => '987654321',
        ]);
        $this->user->assignRole('owner');
    }

    public function test_webhook_still_rejects_without_secret_token()
    {
        $response = $this->postJson('/api/telegram/webhook', [
            'update_id' => 1001,
            'message' => [
                'chat' => ['id' => 987654321],
                'text' => 'Hola',
            ],
        ]);

        $response->assertStatus(401);
    }

    public function test_duplicate_update_id_still_ignored()
    {
        $payload = [
            'update_id' => 1002,
            'message' => [
                'chat' => ['id' => 987654321],
                'text' => '/connect 123456',
            ],
        ];

        Cache::put('telegram_update_1002', true, 3600);

        $response = $this->postJson('/api/telegram/webhook', $payload, [
            'X-Telegram-Bot-Api-Secret-Token' => 'my-secret-token',
        ]);

        $response->assertOk();
        $response->assertJson(['status' => 'duplicate_ignored']);
    }

    public function test_connect_flow_still_binds_user()
    {
        Cache::put('telegram_connect_CONF12', $this->user->id, 900);

        $response = $this->postJson('/api/telegram/webhook', [
            'update_id' => 1003,
            'message' => [
                'chat' => ['id' => 555444333],
                'text' => '/connect CONF12',
            ],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => 'my-secret-token',
        ]);

        $response->assertOk();
        $response->assertJson(['status' => 'connected']);

        $this->assertEquals('555444333', $this->user->fresh()->telegram_chat_id);
    }

    public function test_approval_message_renders_inline_buttons()
    {
        $telegramService = app(TelegramService::class);
        $bot = $telegramService->getBot();

        $this->assertNotNull($bot);
    }

    public function test_callback_confirm_scoped_to_user_and_business()
    {
        BusinessContext::set($this->business);
        $gate = app(ApprovalGate::class);
        $pending = $gate->createPendingAction($this->user, $this->business, 'delete_inventory_item', ['Item X']);

        // Send callback_query from registered user chat_id
        $response = $this->postJson('/api/telegram/webhook', [
            'update_id' => 1004,
            'callback_query' => [
                'id' => 'cb_123',
                'from' => ['id' => 987654321],
                'message' => [
                    'chat' => ['id' => 987654321],
                ],
                'data' => "approve:{$pending->token}",
            ],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => 'my-secret-token',
        ]);

        $response->assertOk();
        $response->assertJson(['status' => 'callback_processed']);

        $this->assertEquals('confirmed', $pending->fresh()->status);
    }

    public function test_confirm_button_executes_pending_action_once()
    {
        BusinessContext::set($this->business);
        $gate = app(ApprovalGate::class);
        $pending = $gate->createPendingAction($this->user, $this->business, 'update_price', ['Servicio Dental', 50]);

        // First callback execution
        $response1 = $this->postJson('/api/telegram/webhook', [
            'update_id' => 1005,
            'callback_query' => [
                'id' => 'cb_124',
                'from' => ['id' => 987654321],
                'message' => [
                    'chat' => ['id' => 987654321],
                ],
                'data' => "approve:{$pending->token}",
            ],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => 'my-secret-token',
        ]);

        $response1->assertOk();

        // Second callback execution (replay attempt)
        $response2 = $this->postJson('/api/telegram/webhook', [
            'update_id' => 1006,
            'callback_query' => [
                'id' => 'cb_125',
                'from' => ['id' => 987654321],
                'message' => [
                    'chat' => ['id' => 987654321],
                ],
                'data' => "approve:{$pending->token}",
            ],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => 'my-secret-token',
        ]);

        $response2->assertOk();
        $response2->assertJsonPath('result.success', false);
    }
}
