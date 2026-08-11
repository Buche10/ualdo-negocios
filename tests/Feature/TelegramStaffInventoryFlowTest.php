<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessIntegration;
use App\Models\IntegrationMapping;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\User;
use App\Services\ApprovalGate;
use App\Services\BusinessContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramStaffInventoryFlowTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;

    protected User $staff;

    protected InventoryItem $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        config(['services.telegram.secret_token' => 'staff-secret-token']);

        $this->business = Business::create([
            'name' => 'Restaurante Telegram Staff Test',
            'slug' => 'restaurante-telegram-staff-test',
            'vertical' => 'restaurant',
        ]);

        BusinessContext::set($this->business);

        $this->staff = User::create([
            'business_id' => $this->business->id,
            'name' => 'Mario Cocinero',
            'email' => 'mario.cocinero@test.com',
            'email_verified_at' => now(),
            'password' => bcrypt('password'),
            'telegram_chat_id' => '1122334455',
        ]);
        $this->staff->assignRole('owner');

        BusinessIntegration::create([
            'business_id' => $this->business->id,
            'provider' => 'doblefilo',
            'base_url' => 'https://doblefilo.vercel.app',
            'api_key' => 'secret_staff_key_777',
            'enabled' => true,
        ]);

        $this->item = InventoryItem::create([
            'business_id' => $this->business->id,
            'name' => 'Carne Bondiola',
            'type' => 'supply',
            'stock' => 15,
            'attributes' => ['unit' => 'kg', 'sku' => 'PRD-BONDIOLA'],
        ]);

        IntegrationMapping::create([
            'business_id' => $this->business->id,
            'provider' => 'doblefilo',
            'external_id' => 'PRD-BONDIOLA',
            'external_uid' => 'mongo_bondiola_999',
            'inventory_item_id' => $this->item->id,
        ]);
    }

    public function test_telegram_callback_confirms_inventory_adjustment_single_use(): void
    {
        Http::fake([
            'https://doblefilo.vercel.app/api/inventory/movements' => Http::response([
                'success' => true,
                'movementId' => 'mov_112233',
            ], 201),
        ]);

        $gate = app(ApprovalGate::class);
        $pending = $gate->createPendingAction(
            user: $this->staff,
            business: $this->business,
            action: 'inventory_adjustment',
            payload: [
                'item_id' => $this->item->id,
                'quantity' => 5,
                'kind' => 'out',
                'location' => 'kitchen',
            ]
        );

        // First callback execution via Telegram webhook
        $response1 = $this->postJson('/api/telegram/webhook', [
            'update_id' => 9001,
            'callback_query' => [
                'id' => 'cb_inv_1',
                'from' => ['id' => 1122334455],
                'message' => [
                    'chat' => ['id' => 1122334455],
                ],
                'data' => "approve:{$pending->token}",
            ],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => 'staff-secret-token',
        ]);

        $response1->assertOk();
        $response1->assertJson(['status' => 'callback_processed']);

        // Assert pending action status is confirmed
        $this->assertEquals('confirmed', $pending->fresh()->status);

        // Assert stock updated locally: 15 - 5 = 10
        $this->item->refresh();
        $this->assertEquals(10, $this->item->stock);

        // Assert InventoryTransaction created
        $tx = InventoryTransaction::where('inventory_item_id', $this->item->id)->first();
        $this->assertNotNull($tx);
        $this->assertEquals(-5, $tx->quantity);

        // Assert HTTP POST to Doble Filo was sent
        Http::assertSent(function ($request) {
            return $request['productId'] === 'mongo_bondiola_999'
                && $request['movementType'] === 'output'
                && $request['quantity'] === 5;
        });

        // Second callback execution attempt (replay attack)
        $response2 = $this->postJson('/api/telegram/webhook', [
            'update_id' => 9002,
            'callback_query' => [
                'id' => 'cb_inv_2',
                'from' => ['id' => 1122334455],
                'message' => [
                    'chat' => ['id' => 1122334455],
                ],
                'data' => "approve:{$pending->token}",
            ],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => 'staff-secret-token',
        ]);

        $response2->assertOk();
        $response2->assertJsonPath('result.success', false);

        // Assert stock remains 10 (no double deduction)
        $this->assertEquals(10, $this->item->fresh()->stock);
    }
}
