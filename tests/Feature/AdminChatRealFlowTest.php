<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\InventoryItem;
use App\Models\Invitation;
use App\Models\User;
use App\Services\AdminTools\AdminReadTools;
use App\Services\ApprovalGate;
use App\Services\BusinessContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminChatRealFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_roles_are_seeded()
    {
        $this->seed(RoleSeeder::class);

        $this->assertDatabaseHas('roles', ['name' => 'owner', 'guard_name' => 'web']);
        $this->assertDatabaseHas('roles', ['name' => 'receptionist', 'guard_name' => 'web']);
        $this->assertDatabaseHas('roles', ['name' => 'doctor', 'guard_name' => 'web']);
    }

    public function test_freshly_onboarded_owner_can_execute_admin_tool()
    {
        $this->seed(RoleSeeder::class);

        /** @var User $user */
        $user = User::create([
            'name' => 'Nuevo Propietario',
            'email' => 'owner.onboard@test.com',
            'email_verified_at' => now(),
            'password' => bcrypt('password'),
        ]);

        $this->actingAs($user);

        // Real onboarding request
        $response = $this->post('/onboarding', [
            'name' => 'Consultorio Real Onboarded',
            'vertical' => 'health',
            'working_days' => ['monday', 'tuesday'],
            'business_hours_start' => '08:00',
            'business_hours_end' => '17:00',
            'slot_duration_minutes' => 30,
        ]);

        $response->assertRedirect(route('dashboard'));

        $user = $user->fresh();
        $this->assertNotNull($user->business_id);
        $this->assertTrue($user->hasRole('owner'));
        $this->assertEquals('owner', $user->role);

        // Verify newly onboarded owner can use admin chat tools without 'Acceso denegado'
        $chatResponse = $this->postJson('/admin/chat', ['message' => '¿Qué insumos me faltan?']);
        $chatResponse->assertOk();
        $this->assertStringNotContainsString('Acceso denegado', $chatResponse->json('reply'));
    }

    public function test_invited_receptionist_receives_spatie_role()
    {
        $this->seed(RoleSeeder::class);

        $business = Business::create([
            'name' => 'Consultorio Invites',
            'slug' => 'consultorio-invites',
            'vertical' => 'health',
        ]);

        $owner = User::create([
            'business_id' => $business->id,
            'name' => 'Propietario Invita',
            'email' => 'owner.inviter@test.com',
            'email_verified_at' => now(),
            'password' => bcrypt('password'),
        ]);
        $owner->assignRole('owner');

        $this->actingAs($owner);

        // Send invitation
        $inviteResponse = $this->post('/team/invite', [
            'email' => 'recepcion.nueva@test.com',
            'role' => 'receptionist',
        ]);
        $inviteResponse->assertRedirect();

        /** @var Invitation $invitation */
        $invitation = Invitation::withoutGlobalScopes()->where('email', 'recepcion.nueva@test.com')->first();
        $this->assertNotNull($invitation);

        // Create invited user and accept
        /** @var User $invitedUser */
        $invitedUser = User::create([
            'name' => 'Recepcionista Nueva',
            'email' => 'recepcion.nueva@test.com',
            'email_verified_at' => now(),
            'password' => bcrypt('password'),
        ]);

        $this->actingAs($invitedUser);
        $acceptResponse = $this->get(route('invitations.accept', ['token' => $invitation->token]));
        $acceptResponse->assertRedirect(route('dashboard'));

        $invitedUser = $invitedUser->fresh();
        $this->assertEquals($business->id, $invitedUser->business_id);
        $this->assertTrue($invitedUser->hasRole('receptionist'));
        $this->assertEquals('receptionist', $invitedUser->role);
    }

    public function test_concurrent_confirm_executes_action_only_once()
    {
        $this->seed(RoleSeeder::class);

        $business = Business::create([
            'name' => 'Consultorio Concurrente',
            'slug' => 'consultorio-concurrente',
            'vertical' => 'health',
        ]);

        $owner = User::create([
            'business_id' => $business->id,
            'name' => 'Owner Race',
            'email' => 'race@test.com',
            'email_verified_at' => now(),
            'password' => bcrypt('password'),
        ]);
        $owner->assignRole('owner');

        BusinessContext::set($business);

        $item = InventoryItem::create([
            'business_id' => $business->id,
            'name' => 'Item Para Borrar',
            'stock' => 5,
            'price' => 20.0,
        ]);

        $gate = app(ApprovalGate::class);
        $pending = $gate->createPendingAction($owner, $business, 'delete_inventory_item', ['Item Para Borrar']);

        $executionCount = 0;
        $executor = function ($action, $payload, $u) use (&$executionCount, $item) {
            $executionCount++;
            $item->delete();

            return 'Eliminado';
        };

        // First confirmation claim
        $result1 = $gate->confirmAction($pending->token, $owner, $executor);

        // Second concurrent confirmation claim on same token
        $result2 = $gate->confirmAction($pending->token, $owner, $executor);

        $this->assertTrue($result1['success']);
        $this->assertFalse($result2['success']);
        $this->assertEquals(1, $executionCount);
        $this->assertStringContainsString('ya fue procesada', $result2['message']);
    }

    public function test_admin_tool_enforces_strict_business_context()
    {
        $this->seed(RoleSeeder::class);

        $businessA = Business::create([
            'name' => 'Negocio A',
            'slug' => 'negocio-a',
            'vertical' => 'health',
        ]);

        $businessB = Business::create([
            'name' => 'Negocio B',
            'slug' => 'negocio-b',
            'vertical' => 'health',
        ]);

        $ownerA = User::create([
            'business_id' => $businessA->id,
            'name' => 'Owner A',
            'email' => 'ownera@test.com',
            'email_verified_at' => now(),
            'password' => bcrypt('password'),
        ]);
        $ownerA->assignRole('owner');

        // BusinessContext set to Business B while user belongs to Business A
        BusinessContext::set($businessB);

        $readTools = new AdminReadTools;
        $tool = $readTools->whatToBuyTool($ownerA);

        $resultJson = (string) $tool->handle();
        $data = json_decode($resultJson, true);

        $this->assertEquals('error', $data['status']);
        $this->assertStringContainsString('Error de seguridad', $data['message']);
    }
}
