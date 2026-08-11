<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Contact;
use App\Models\User;
use App\Services\BusinessContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class InboxWebHandoffTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_can_list_conversations()
    {
        $business = Business::create(['name' => 'Consultorio Alpha', 'slug' => 'alpha', 'vertical' => 'health']);
        $user = User::factory()->create(['business_id' => $business->id, 'role' => 'receptionist']);

        BusinessContext::runInContext($business, fn () => Contact::create(['phone_number' => '59399111222', 'name' => 'Paciente Alpha']));

        $response = $this->actingAs($user)->get('/inbox');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Inbox/Index')
            ->has('conversations.data', 1)
        );
    }

    public function test_reply_sends_whatsapp_and_pauses_bot()
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messaging_product' => 'whatsapp'], 200)]);

        $business = Business::create([
            'name' => 'Consultorio Beta',
            'slug' => 'beta',
            'vertical' => 'health',
            'whatsapp_phone_number_id' => 'phone_beta_555',
            'whatsapp_access_token' => 'token_beta_666',
        ]);
        $user = User::factory()->create(['business_id' => $business->id, 'role' => 'receptionist']);

        $contact = BusinessContext::runInContext($business, fn () => Contact::create(['phone_number' => '59399222333', 'name' => 'Paciente Beta']));

        $response = $this->actingAs($user)->post("/inbox/{$contact->id}/reply", [
            'message' => 'Hola, confirmamos su cita para mañana a las 10:00.',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('message');

        // Bot pausado por 24h
        $this->assertNotNull($contact->fresh()->bot_paused_until);

        // HTTP enviado con las credenciales del negocio
        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/phone_beta_555/messages')
                && $request->hasHeader('Authorization', 'Bearer token_beta_666');
        });
    }

    public function test_cannot_open_conversation_of_another_business()
    {
        $businessA = Business::create(['name' => 'Alpha', 'slug' => 'alpha', 'vertical' => 'health']);
        $businessB = Business::create(['name' => 'Beta', 'slug' => 'beta', 'vertical' => 'health']);

        $userA = User::factory()->create(['business_id' => $businessA->id]);
        $contactB = BusinessContext::runInContext($businessB, fn () => Contact::create(['phone_number' => '593999999999', 'name' => 'Paciente B']));

        $response = $this->actingAs($userA)->get("/inbox/{$contactB->id}");

        $response->assertStatus(403);
    }
}
