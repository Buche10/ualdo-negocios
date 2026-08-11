<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Contact;
use App\Models\User;
use App\Services\BusinessContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhoneValidationTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $this->business = Business::create([
            'name' => 'Consultorio Phone Test',
            'slug' => 'consultorio-phone-test',
            'vertical' => 'health',
        ]);

        $this->owner = User::create([
            'business_id' => $this->business->id,
            'name' => 'Owner Phone',
            'email' => 'phone@test.com',
            'email_verified_at' => now(),
            'password' => bcrypt('password'),
        ]);
        $this->owner->assignRole('owner');
    }

    public function test_invalid_phone_is_rejected()
    {
        $this->actingAs($this->owner);

        $response = $this->post('/channels/whatsapp/test', [
            'phone_number' => '12345-not-a-phone',
        ]);

        $response->assertSessionHasErrors(['phone_number']);
    }

    public function test_phone_is_normalized_to_e164_on_save()
    {
        BusinessContext::set($this->business);

        $contact = Contact::create([
            'business_id' => $this->business->id,
            'name' => 'Paciente Ecuador',
            'phone_number' => '+593 99 123 4567',
        ]);

        $this->assertEquals('+593991234567', $contact->fresh()->phone_number);

        $this->business->update([
            'whatsapp_phone_number' => '+593 98 765 4321',
        ]);

        $this->assertEquals('+593987654321', $this->business->fresh()->whatsapp_phone_number);
    }
}
