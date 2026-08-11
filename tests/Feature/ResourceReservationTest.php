<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Business;
use App\Models\Contact;
use App\Models\Resource;
use App\Services\BusinessContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResourceReservationTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create([
            'name' => 'Spa & Barbería Multi-Recurso',
            'slug' => 'spa-barberia',
            'vertical' => 'generic',
            'whatsapp_phone_number_id' => 'phone_resource_123',
        ]);

        BusinessContext::set($this->business);
    }

    public function test_different_resources_can_be_reserved_in_same_time_slot(): void
    {
        $chairA = Resource::create([
            'business_id' => $this->business->id,
            'name' => 'Sillón Barbería 1',
            'type' => 'chair',
            'is_active' => true,
        ]);

        $chairB = Resource::create([
            'business_id' => $this->business->id,
            'name' => 'Sillón Barbería 2',
            'type' => 'chair',
            'is_active' => true,
        ]);

        $contact1 = Contact::create(['phone_number' => '593911111111', 'name' => 'Juan']);
        $contact2 = Contact::create(['phone_number' => '593922222222', 'name' => 'Pedro']);

        $tz = 'America/Guayaquil';
        $start = Carbon::tomorrow($tz)->setHour(10)->setMinute(0);
        $end = $start->copy()->addMinutes(45);

        $app1 = Appointment::create([
            'contact_id' => $contact1->id,
            'resource_id' => $chairA->id,
            'title' => 'Corte de Cabello',
            'start_time' => $start,
            'end_time' => $end,
            'status' => 'scheduled',
        ]);

        $app2 = Appointment::create([
            'contact_id' => $contact2->id,
            'resource_id' => $chairB->id,
            'title' => 'Barba',
            'start_time' => $start,
            'end_time' => $end,
            'status' => 'scheduled',
        ]);

        $this->assertNotNull($app1);
        $this->assertNotNull($app2);
        $this->assertEquals($chairA->id, $app1->resource_id);
        $this->assertEquals($chairB->id, $app2->resource_id);
    }

    public function test_overlapping_reservation_on_same_resource_is_rejected(): void
    {
        $station = Resource::create([
            'business_id' => $this->business->id,
            'name' => 'Estación Manicure',
            'type' => 'station',
            'is_active' => true,
        ]);

        $contact = Contact::create(['phone_number' => '593933333333', 'name' => 'Maria']);

        $tz = 'America/Guayaquil';
        $start1 = Carbon::tomorrow($tz)->setHour(14)->setMinute(0);
        $end1 = $start1->copy()->addMinutes(60);

        Appointment::create([
            'contact_id' => $contact->id,
            'resource_id' => $station->id,
            'title' => 'Uñas Acrílicas',
            'start_time' => $start1,
            'end_time' => $end1,
            'status' => 'scheduled',
        ]);

        $start2 = Carbon::tomorrow($tz)->setHour(14)->setMinute(30);
        $end2 = $start2->copy()->addMinutes(45);

        // Intento de solapamiento
        $hasOverlap = Appointment::where('resource_id', $station->id)
            ->where('status', '!=', 'cancelled')
            ->where('start_time', '<', $end2)
            ->where('end_time', '>', $start2)
            ->exists();

        $this->assertTrue($hasOverlap);
    }
}
