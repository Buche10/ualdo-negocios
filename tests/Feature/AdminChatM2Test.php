<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use App\Services\AdminTools\AdminConfigTools;
use App\Services\BusinessContext;
use App\Services\UaldoAdminService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminChatM2Test extends TestCase
{
    use RefreshDatabase;

    protected Business $business;

    protected User $owner;

    protected User $receptionist;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create([
            'name' => 'Consultorio M2 Persona',
            'slug' => 'consultorio-m2',
            'vertical' => 'health',
            'timezone' => 'America/Guayaquil',
        ]);

        $this->owner = User::create([
            'business_id' => $this->business->id,
            'name' => 'Dra. María Owner',
            'email' => 'owner@m2.com',
            'email_verified_at' => now(),
            'password' => bcrypt('password'),
            'role' => 'owner',
        ]);

        $this->receptionist = User::create([
            'business_id' => $this->business->id,
            'name' => 'Carlos Recepción',
            'email' => 'reception@m2.com',
            'email_verified_at' => now(),
            'password' => bcrypt('password'),
            'role' => 'receptionist',
        ]);
    }

    public function test_only_owner_edits_persona()
    {
        BusinessContext::set($this->business);

        $configTools = new AdminConfigTools;
        $tool = $configTools->updateAiPersonaTool($this->receptionist);

        $resJson = (string) $tool->handle('Sé extremadamente formal');
        $res = json_decode($resJson, true);

        $this->assertEquals('error', $res['status']);
        $this->assertStringContainsString('Acceso denegado', $res['message']);
    }

    public function test_invalid_hours_rejected()
    {
        BusinessContext::set($this->business);

        $configTools = new AdminConfigTools;
        $tool = $configTools->updateBusinessHoursTool($this->owner);

        $resJson = (string) $tool->handle('18:00', '09:00');
        $res = json_decode($resJson, true);

        $this->assertEquals('approval_required', $res['status']);
    }

    public function test_persona_change_reflects_in_3_layer_system_prompt()
    {
        BusinessContext::set($this->business);

        // Set custom persona in business settings
        $this->business->update([
            'settings' => ['ai_persona' => 'Trata a todos los pacientes con tono alegre, juvenil y cercano.'],
        ]);

        $service = app(UaldoAdminService::class);
        $prompt = $service->build3LayerSystemPrompt($this->business->fresh(), $this->owner);

        $this->assertStringContainsString('CAPA 1: IDENTIDAD BASE', $prompt);
        $this->assertStringContainsString('CAPA 2: PERSONALIDAD DEL NEGOCIO', $prompt);
        $this->assertStringContainsString('Trata a todos los pacientes con tono alegre', $prompt);
        $this->assertStringContainsString('CAPA 3: REGLAS INMUTABLES', $prompt);
    }
}
