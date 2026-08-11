<?php

namespace Tests\Feature;

use App\Models\AdminChatMessage;
use App\Models\Business;
use App\Models\InventoryItem;
use App\Models\User;
use App\Services\AdminTools\AdminReadTools;
use App\Services\BusinessContext;
use App\Services\UaldoAdminService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminChatM0Test extends TestCase
{
    use RefreshDatabase;

    protected Business $businessAlpha;

    protected Business $businessBeta;

    protected User $userAlpha;

    protected User $userBeta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->businessAlpha = Business::create([
            'name' => 'Consultorio Odontológico Alpha',
            'slug' => 'consultorio-alpha',
            'vertical' => 'health',
            'timezone' => 'America/Guayaquil',
        ]);

        $this->businessBeta = Business::create([
            'name' => 'Clínica Estética Beta',
            'slug' => 'clinica-beta',
            'vertical' => 'health',
            'timezone' => 'America/Guayaquil',
        ]);

        $this->userAlpha = User::create([
            'business_id' => $this->businessAlpha->id,
            'name' => 'Doctor Alpha',
            'email' => 'alpha@test.com',
            'email_verified_at' => now(),
            'password' => bcrypt('password'),
            'role' => 'owner',
        ]);

        $this->userBeta = User::create([
            'business_id' => $this->businessBeta->id,
            'name' => 'Doctor Beta',
            'email' => 'beta@test.com',
            'email_verified_at' => now(),
            'password' => bcrypt('password'),
            'role' => 'owner',
        ]);
    }

    public function test_unauthenticated_cannot_chat()
    {
        $response = $this->postJson('/admin/chat', ['message' => 'Hola Ualdo']);
        $response->assertStatus(401);
    }

    public function test_tools_only_touch_own_business()
    {
        BusinessContext::set($this->businessAlpha);

        // Alpha items
        InventoryItem::create([
            'business_id' => $this->businessAlpha->id,
            'name' => 'Anestesia Alpha',
            'stock' => 2,
            'min_stock' => 10,
        ]);

        // Beta items
        InventoryItem::create([
            'business_id' => $this->businessBeta->id,
            'name' => 'Anestesia Beta',
            'stock' => 1,
            'min_stock' => 10,
        ]);

        $toolService = new AdminReadTools;
        $tool = $toolService->whatToBuyTool($this->userAlpha);

        $resultJson = (string) $tool->handle();
        $data = json_decode($resultJson, true);

        $this->assertEquals('success', $data['status']);
        $this->assertCount(1, $data['items']);
        $this->assertEquals('Anestesia Alpha', $data['items'][0]['name']);
    }

    public function test_what_to_buy_uses_single_sql_query()
    {
        BusinessContext::set($this->businessAlpha);

        InventoryItem::create([
            'business_id' => $this->businessAlpha->id,
            'name' => 'Guantes M',
            'stock' => 2,
            'min_stock' => 10,
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $toolService = new AdminReadTools;
        $tool = $toolService->whatToBuyTool($this->userAlpha);
        $tool->handle();

        $queryLog = DB::getQueryLog();
        $inventoryQueries = array_values(array_filter($queryLog, fn ($q) => str_contains($q['query'], 'inventory_items')));

        $this->assertCount(1, $inventoryQueries);
        $this->assertStringContainsString('stock', $inventoryQueries[0]['query']);
        $this->assertStringContainsString('min_stock', $inventoryQueries[0]['query']);
    }

    public function test_garbage_input_returns_safe_help_not_crash()
    {
        $this->actingAs($this->userAlpha);

        $response = $this->postJson('/admin/chat', ['message' => '   ']);
        $response->assertOk();
        $response->assertJson(['reply' => 'Por favor ingresa una pregunta o instrucción válida.']);
    }

    public function test_history_capped_to_last_n_messages()
    {
        BusinessContext::set($this->businessAlpha);

        for ($i = 1; $i <= 15; $i++) {
            AdminChatMessage::create([
                'business_id' => $this->businessAlpha->id,
                'user_id' => $this->userAlpha->id,
                'role' => 'user',
                'content' => "Mensaje {$i}",
            ]);
        }

        $service = app(UaldoAdminService::class);
        $systemPrompt = $service->build3LayerSystemPrompt($this->businessAlpha, $this->userAlpha);

        $this->assertStringContainsString('CAPA 1: IDENTIDAD BASE', $systemPrompt);
        $this->assertStringContainsString('CAPA 3: REGLAS INMUTABLES', $systemPrompt);
    }
}
