<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OnboardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_without_business_is_redirected_to_onboarding()
    {
        $user = User::factory()->create([
            'business_id' => null,
            'email_verified_at' => now(),
        ]);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertRedirect(route('onboarding'));
    }

    public function test_unverified_user_without_business_is_not_redirected_to_onboarding()
    {
        $user = User::factory()->unverified()->withoutBusiness()->create();

        $response = $this->actingAs($user)->get('/verify-email');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->component('Auth/VerifyEmail'));
    }

    public function test_user_with_business_is_redirected_away_from_onboarding()
    {
        $business = Business::create([
            'name' => 'Consultorio Existente',
            'slug' => 'consultorio-existente',
            'vertical' => 'health',
        ]);

        $user = User::factory()->create([
            'business_id' => $business->id,
            'email_verified_at' => now(),
        ]);

        $response = $this->actingAs($user)->get('/onboarding');

        $response->assertRedirect(route('dashboard'));
    }

    public function test_onboarding_store_creates_business_and_links_user()
    {
        $user = User::factory()->create([
            'business_id' => null,
            'email_verified_at' => now(),
        ]);

        $payload = [
            'name' => 'Barbería Estilo Capital',
            'vertical' => 'barbershop',
            'working_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'],
            'business_hours_start' => '09:00',
            'business_hours_end' => '19:00',
            'slot_duration_minutes' => 30,
        ];

        $response = $this->actingAs($user)->post('/onboarding', $payload);

        $response->assertRedirect(route('dashboard'));

        $user->refresh();
        $this->assertNotNull($user->business_id);

        $business = Business::find($user->business_id);
        $this->assertEquals('Barbería Estilo Capital', $business->name);
        $this->assertEquals('barbershop', $business->vertical);
        $this->assertEquals(30, $business->slot_duration_minutes);
    }

    public function test_onboarding_rejects_invalid_payload()
    {
        $user = User::factory()->create([
            'business_id' => null,
            'email_verified_at' => now(),
        ]);

        // Payload con nombre vacío y horas invertidas (start > end)
        $invalidPayload = [
            'name' => '',
            'vertical' => 'health',
            'working_days' => ['monday'],
            'business_hours_start' => '18:00',
            'business_hours_end' => '09:00',
            'slot_duration_minutes' => 45,
        ];

        $response = $this->actingAs($user)->post('/onboarding', $invalidPayload);

        $response->assertSessionHasErrors(['name', 'business_hours_end']);
        $this->assertNull($user->fresh()->business_id);
    }

    public function test_onboarding_applies_ecuador_defaults()
    {
        $user = User::factory()->create([
            'business_id' => null,
            'email_verified_at' => now(),
        ]);

        $payload = [
            'name' => 'Clínica Dental Guayaquil',
            'vertical' => 'health',
            'working_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
            'business_hours_start' => '08:30',
            'business_hours_end' => '17:30',
            'slot_duration_minutes' => 45,
        ];

        $this->actingAs($user)->post('/onboarding', $payload);

        $business = Business::where('name', 'Clínica Dental Guayaquil')->first();
        $this->assertNotNull($business);
        $this->assertEquals('America/Guayaquil', $business->timezone);
        $this->assertEquals('USD', $business->currency);
    }
}
