<?php

namespace Tests\Feature;

use App\Jobs\ProvisionCrmWorkspaceJob;
use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CrmProvisioningAndSsoTest extends TestCase
{
    use RefreshDatabase;

    public function test_onboarding_dispatches_provisioning_job()
    {
        Queue::fake();

        $user = User::factory()->withoutBusiness()->create(['email_verified_at' => now()]);

        $payload = [
            'name' => 'Consultorio CRM Test',
            'vertical' => 'health',
            'working_days' => ['monday'],
            'business_hours_start' => '09:00',
            'business_hours_end' => '18:00',
            'slot_duration_minutes' => 45,
        ];

        $this->actingAs($user)->post('/onboarding', $payload);

        Queue::assertPushed(ProvisionCrmWorkspaceJob::class);
    }

    public function test_job_stores_workspace_id()
    {
        $business = Business::create(['name' => 'Consultorio Nexo', 'slug' => 'nexo', 'vertical' => 'health']);

        $job = new ProvisionCrmWorkspaceJob($business->id);
        $job->handle();

        $business->refresh();
        $this->assertNotNull($business->crm_workspace_id);
        $this->assertStringContainsString('crm_ws_nexo', $business->crm_workspace_id);
    }

    public function test_sso_generates_signed_token()
    {
        $business = Business::create([
            'name' => 'Consultorio SSO',
            'slug' => 'sso-biz',
            'vertical' => 'health',
            'crm_workspace_id' => 'crm_ws_sso-biz',
        ]);
        $user = User::factory()->create(['business_id' => $business->id]);

        $response = $this->actingAs($user)->get('/sso/crm');

        $response->assertRedirect();
        $targetUrl = $response->headers->get('Location');
        $this->assertStringContainsString('https://crm.nexoteams.com/auth/sso?token=', $targetUrl);
    }

    public function test_sso_token_cannot_be_replayed()
    {
        $business = Business::create([
            'name' => 'Consultorio Replay',
            'slug' => 'replay-biz',
            'vertical' => 'health',
            'crm_workspace_id' => 'crm_ws_replay',
        ]);
        $user = User::factory()->create(['business_id' => $business->id]);

        // Generar URL firmada de SSO
        $ssoRedirect = $this->actingAs($user)->get('/sso/crm');
        $location = $ssoRedirect->headers->get('Location');

        // Extraer token de la URL de destino
        parse_str(parse_url($location, PHP_URL_QUERY), $queryParams);
        $signedVerifyUrl = urldecode($queryParams['token']);

        // Primer intento de verificacion -> 200 OK
        $verifyRes1 = $this->get($signedVerifyUrl);
        $verifyRes1->assertStatus(200);

        // Segundo intento con la misma URL firmada (Replay Attack) -> 401 Replay error
        $verifyRes2 = $this->get($signedVerifyUrl);
        $verifyRes2->assertStatus(401);
        $verifyRes2->assertJson(['error' => 'El token SSO ya ha sido utilizado (replay attack)']);
    }
}
