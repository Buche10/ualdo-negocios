<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Business;
use App\Models\User;
use App\Services\AdminTools\AdminCrossSkillTools;
use App\Services\BusinessContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminChatM3Test extends TestCase
{
    use RefreshDatabase;

    protected Business $businessAlpha;

    protected Business $businessBeta;

    protected User $adminAlpha;

    protected User $staffAlpha;

    protected User $staffBeta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->businessAlpha = Business::create([
            'name' => 'Consultorio Odontológico Alpha M3',
            'slug' => 'consultorio-alpha-m3',
            'vertical' => 'health',
            'timezone' => 'America/Guayaquil',
        ]);

        $this->businessBeta = Business::create([
            'name' => 'Clínica Beta M3',
            'slug' => 'clinica-beta-m3',
            'vertical' => 'health',
            'timezone' => 'America/Guayaquil',
        ]);

        $this->adminAlpha = User::create([
            'business_id' => $this->businessAlpha->id,
            'name' => 'Dr. Admin Alpha',
            'email' => 'admin@alpha.com',
            'email_verified_at' => now(),
            'password' => bcrypt('password'),
            'role' => 'owner',
        ]);

        $this->staffAlpha = User::create([
            'business_id' => $this->businessAlpha->id,
            'name' => 'Enfermera Ana',
            'email' => 'ana@alpha.com',
            'email_verified_at' => now(),
            'password' => bcrypt('password'),
            'role' => 'receptionist',
        ]);

        $this->staffBeta = User::create([
            'business_id' => $this->businessBeta->id,
            'name' => 'Enfermero Bruno',
            'email' => 'bruno@beta.com',
            'email_verified_at' => now(),
            'password' => bcrypt('password'),
            'role' => 'receptionist',
        ]);
    }

    public function test_admin_assigned_task_stays_in_own_business()
    {
        BusinessContext::set($this->businessAlpha);

        $crossTools = new AdminCrossSkillTools;
        $tool = $crossTools->assignTaskTool($this->adminAlpha);

        $resJson = (string) $tool->handle('Enfermera Ana', 'Revisar inventario de anestesia', '2026-08-10');
        $res = json_decode($resJson, true);

        $this->assertEquals('success', $res['status']);
        $this->assertDatabaseHas('tasks', [
            'business_id' => $this->businessAlpha->id,
            'assigned_to' => $this->staffAlpha->id,
            'title' => 'Revisar inventario de anestesia',
        ]);
    }

    public function test_cannot_assign_task_to_staff_in_another_business()
    {
        BusinessContext::set($this->businessAlpha);

        $crossTools = new AdminCrossSkillTools;
        $tool = $crossTools->assignTaskTool($this->adminAlpha);

        $resJson = (string) $tool->handle('Enfermero Bruno', 'Tarea No Autorizada', null);
        $res = json_decode($resJson, true);

        $this->assertEquals('error', $res['status']);
        $this->assertStringContainsString('No se encontró ningún miembro', $res['message']);
    }

    public function test_get_agenda_stats_calculates_correct_metrics()
    {
        BusinessContext::set($this->businessAlpha);

        $now = Carbon::now('America/Guayaquil');

        Appointment::create([
            'business_id' => $this->businessAlpha->id,
            'title' => 'Limpieza Dental',
            'start_time' => $now->copy()->addHours(1),
            'end_time' => $now->copy()->addHours(2),
            'status' => 'scheduled',
        ]);

        Appointment::create([
            'business_id' => $this->businessAlpha->id,
            'title' => 'Extracción',
            'start_time' => $now->copy()->addHours(3),
            'end_time' => $now->copy()->addHours(4),
            'status' => 'completed',
        ]);

        $crossTools = new AdminCrossSkillTools;
        $tool = $crossTools->getAgendaStatsTool($this->adminAlpha);

        $resJson = (string) $tool->handle('today');
        $res = json_decode($resJson, true);

        $this->assertEquals('success', $res['status']);
        $this->assertEquals(2, $res['metrics']['total_appointments']);
        $this->assertEquals(1, $res['metrics']['scheduled']);
        $this->assertEquals(1, $res['metrics']['completed']);
    }
}
