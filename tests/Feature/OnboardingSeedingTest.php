<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use App\Services\BusinessContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OnboardingSeedingTest extends TestCase
{
    use RefreshDatabase;

    public function test_onboarding_health_business_seeds_doctor_and_health_services(): void
    {
        $user = User::factory()->withoutBusiness()->create(['email_verified_at' => now()]);

        $response = $this->actingAs($user)->post('/onboarding', [
            'name' => 'Clínica Salud Y Vida',
            'vertical' => 'health',
            'working_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
            'business_hours_start' => '08:00',
            'business_hours_end' => '17:00',
            'slot_duration_minutes' => 30,
        ]);

        $response->assertRedirect('/dashboard');

        $user->refresh();
        $this->assertNotNull($user->business_id);

        $business = Business::find($user->business_id);
        $this->assertEquals('health', $business->vertical);

        BusinessContext::set($business);

        $this->assertDatabaseHas('resources', [
            'business_id' => $business->id,
            'type' => 'doctor',
        ]);

        $this->assertDatabaseHas('inventory_items', [
            'business_id' => $business->id,
            'name' => 'Consulta Médica General',
        ]);
    }

    public function test_onboarding_restaurant_business_seeds_tables_and_menu_items(): void
    {
        $user = User::factory()->withoutBusiness()->create(['email_verified_at' => now()]);

        $response = $this->actingAs($user)->post('/onboarding', [
            'name' => 'Trattoria Italia',
            'vertical' => 'restaurant',
            'working_days' => ['tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'],
            'business_hours_start' => '12:00',
            'business_hours_end' => '22:00',
            'slot_duration_minutes' => 60,
        ]);

        $response->assertRedirect('/dashboard');

        $user->refresh();
        $business = Business::find($user->business_id);
        $this->assertEquals('restaurant', $business->vertical);

        BusinessContext::set($business);

        $this->assertDatabaseHas('resources', [
            'business_id' => $business->id,
            'type' => 'table',
            'name' => 'Mesa 1 (2 personas)',
        ]);

        $this->assertDatabaseHas('inventory_items', [
            'business_id' => $business->id,
            'name' => 'Platillo Ejecutivo',
        ]);
    }
}
