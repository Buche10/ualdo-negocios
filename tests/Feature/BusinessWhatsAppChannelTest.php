<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use App\Services\BusinessContext;
use App\Services\WhatsAppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BusinessWhatsAppChannelTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_save_whatsapp_credentials()
    {
        $business = Business::create([
            'name' => 'Consultorio Alpha',
            'slug' => 'consultorio-alpha',
            'vertical' => 'health',
        ]);

        $user = User::factory()->create(['business_id' => $business->id, 'role' => 'owner']);
        $user->assignRole('owner');

        $payload = [
            'whatsapp_phone_number_id' => 'phone_id_alpha_123',
            'whatsapp_phone_number' => '+593991234567',
            'whatsapp_access_token' => 'token_secret_meta_999',
        ];

        $response = $this->actingAs($user)->post('/channels/whatsapp', $payload);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();
        $business->refresh();

        $this->assertEquals('phone_id_alpha_123', $business->whatsapp_phone_number_id);
        $this->assertEquals('token_secret_meta_999', $business->whatsapp_access_token);
        $this->assertTrue($business->has_whatsapp_token);
    }

    public function test_receptionist_cannot_manage_whatsapp_channels()
    {
        $business = Business::create([
            'name' => 'Consultorio Guard',
            'slug' => 'consultorio-guard',
            'vertical' => 'health',
        ]);

        $receptionist = User::factory()->create(['business_id' => $business->id, 'role' => 'receptionist']);

        $response = $this->actingAs($receptionist)->get('/channels/whatsapp');
        $response->assertStatus(403);

        $postResponse = $this->actingAs($receptionist)->post('/channels/whatsapp', [
            'whatsapp_phone_number_id' => 'hacked_123',
        ]);
        $postResponse->assertStatus(403);
    }

    public function test_token_encrypted_at_rest()
    {
        $business = Business::create([
            'name' => 'Consultorio Encriptado',
            'slug' => 'consultorio-encriptado',
            'vertical' => 'health',
            'whatsapp_phone_number_id' => 'phone_123',
            'whatsapp_access_token' => 'super_secret_token_abc',
        ]);

        $rawRow = DB::table('businesses')->where('id', $business->id)->first();

        // En la base de datos (DB::table raw) la columna debe estar cifrada y NO en texto plano
        $this->assertNotEquals('super_secret_token_abc', $rawRow->whatsapp_access_token);
        $this->assertStringContainsString('eyJ', $rawRow->whatsapp_access_token);

        // A través del Modelo Eloquent debe descifrarse automáticamente
        $this->assertEquals('super_secret_token_abc', $business->fresh()->whatsapp_access_token);
    }

    public function test_token_never_exposed_to_frontend()
    {
        $business = Business::create([
            'name' => 'Consultorio Seguro',
            'slug' => 'consultorio-seguro',
            'vertical' => 'health',
            'whatsapp_phone_number_id' => 'phone_123',
            'whatsapp_access_token' => 'super_secret_token_abc',
        ]);

        $user = User::factory()->create(['business_id' => $business->id, 'role' => 'owner']);

        $response = $this->actingAs($user)->get('/channels/whatsapp');

        $response->assertStatus(200);
        $response->assertDontSee('super_secret_token_abc');
        $response->assertInertia(fn ($page) => $page
            ->where('channel.has_token', true)
            ->where('channel.masked_token', '••••••••_abc')
        );
    }

    public function test_service_uses_saved_tenant_number()
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['messaging_product' => 'whatsapp'], 200),
        ]);

        $business = Business::create([
            'name' => 'Consultorio Beta',
            'slug' => 'consultorio-beta',
            'vertical' => 'health',
            'whatsapp_phone_number_id' => 'phone_tenant_beta_777',
            'whatsapp_access_token' => 'token_tenant_beta_888',
        ]);

        BusinessContext::set($business);

        $ws = new WhatsAppService;
        $ws->sendText('59399111222', 'Hola desde negocio Beta');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/v20.0/phone_tenant_beta_777/messages')
                && $request->hasHeader('Authorization', 'Bearer token_tenant_beta_888');
        });
    }

    public function test_test_message_sends_via_business_number()
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['messaging_product' => 'whatsapp'], 200),
        ]);

        $business = Business::create([
            'name' => 'Consultorio Gamma',
            'slug' => 'consultorio-gamma',
            'vertical' => 'health',
            'whatsapp_phone_number_id' => 'phone_gamma_100',
            'whatsapp_access_token' => 'token_gamma_200',
        ]);

        $user = User::factory()->create(['business_id' => $business->id, 'role' => 'owner']);

        $response = $this->actingAs($user)->post('/channels/whatsapp/test', [
            'phone_number' => '+593999000111',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('message');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/v20.0/phone_gamma_100/messages')
                && $request->hasHeader('Authorization', 'Bearer token_gamma_200');
        });
    }

    public function test_cannot_edit_another_business_channels()
    {
        $businessAlpha = Business::create(['name' => 'Alpha', 'slug' => 'alpha', 'vertical' => 'health']);
        $businessBeta = Business::create(['name' => 'Beta', 'slug' => 'beta', 'vertical' => 'health']);

        $userAlpha = User::factory()->create(['business_id' => $businessAlpha->id, 'role' => 'owner']);

        // Intento de guardar actualiza el negocio del usuario autenticado (businessAlpha), aislando tenant
        $this->actingAs($userAlpha)->post('/channels/whatsapp', [
            'whatsapp_phone_number_id' => 'hacked_phone_id',
        ]);

        $this->assertEquals('hacked_phone_id', $businessAlpha->fresh()->whatsapp_phone_number_id);
        $this->assertNull($businessBeta->fresh()->whatsapp_phone_number_id);
    }

    public function test_invalid_credentials_return_validation_error()
    {
        $user = User::factory()->create(['role' => 'owner']);

        $response = $this->actingAs($user)->post('/channels/whatsapp', [
            'whatsapp_phone_number_id' => '', // requerido
        ]);

        $response->assertSessionHasErrors(['whatsapp_phone_number_id']);
    }
}
