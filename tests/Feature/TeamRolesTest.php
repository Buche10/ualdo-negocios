<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Invitation;
use App\Models\User;
use App\Scopes\BusinessScope;
use App\Services\BusinessContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamRolesTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_invite_team_member()
    {
        $business = Business::create(['name' => 'Consultorio Alpha', 'slug' => 'alpha', 'vertical' => 'health']);
        $owner = User::factory()->create(['business_id' => $business->id, 'role' => 'owner']);

        $response = $this->actingAs($owner)->post('/team/invite', [
            'email' => 'recepcion@alpha.com',
            'role' => 'receptionist',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('invitations', [
            'business_id' => $business->id,
            'email' => 'recepcion@alpha.com',
            'role' => 'receptionist',
        ]);
    }

    public function test_invited_user_joins_correct_business_with_role()
    {
        $business = Business::create(['name' => 'Consultorio Alpha', 'slug' => 'alpha', 'vertical' => 'health']);
        $owner = User::factory()->create(['business_id' => $business->id, 'role' => 'owner']);

        $invitation = BusinessContext::runInContext($business, fn () => Invitation::create([
            'business_id' => $business->id,
            'email' => 'doctor@alpha.com',
            'role' => 'doctor',
            'token' => 'valid_token_123',
            'expires_at' => now()->addHours(24),
            'created_by' => $owner->id,
        ]));

        $newUser = User::factory()->withoutBusiness()->create(['email' => 'doctor@alpha.com']);

        $response = $this->actingAs($newUser)->get("/invitations/accept/{$invitation->token}");

        $response->assertRedirect(route('dashboard'));
        $newUser->refresh();

        $this->assertEquals($business->id, $newUser->business_id);
        $this->assertEquals('doctor', $newUser->role);

        $freshInvite = Invitation::withoutGlobalScope(BusinessScope::class)->find($invitation->id);
        $this->assertNotNull($freshInvite?->accepted_at);
    }

    public function test_invitation_requires_matching_user_email()
    {
        $business = Business::create(['name' => 'Consultorio Check', 'slug' => 'check', 'vertical' => 'health']);

        $invitation = BusinessContext::runInContext($business, fn () => Invitation::create([
            'business_id' => $business->id,
            'email' => 'target@check.com',
            'role' => 'receptionist',
            'token' => 'mismatch_token',
            'expires_at' => now()->addHours(24),
        ]));

        // Intento de aceptar con un email diferente
        $attacker = User::factory()->withoutBusiness()->create(['email' => 'hacker@other.com']);

        $response = $this->actingAs($attacker)->get('/invitations/accept/mismatch_token');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->component('Team/InvitationError'));
        $this->assertNull($attacker->fresh()->business_id);
    }

    public function test_user_with_existing_business_cannot_overwrite_by_invitation()
    {
        $businessOld = Business::create(['name' => 'Old Business', 'slug' => 'old', 'vertical' => 'health']);
        $businessNew = Business::create(['name' => 'New Business', 'slug' => 'new', 'vertical' => 'health']);

        $invitation = BusinessContext::runInContext($businessNew, fn () => Invitation::create([
            'business_id' => $businessNew->id,
            'email' => 'user@old.com',
            'role' => 'doctor',
            'token' => 'overwrite_token',
            'expires_at' => now()->addHours(24),
        ]));

        // Usuario que ya pertenece a businessOld
        $user = User::factory()->create(['business_id' => $businessOld->id, 'email' => 'user@old.com']);

        $response = $this->actingAs($user)->get('/invitations/accept/overwrite_token');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->component('Team/InvitationError'));

        // Su negocio original permanece intacto
        $this->assertEquals($businessOld->id, $user->fresh()->business_id);
    }

    public function test_recepcionista_cannot_invite()
    {
        $business = Business::create(['name' => 'Consultorio Beta', 'slug' => 'beta', 'vertical' => 'health']);
        $receptionist = User::factory()->create(['business_id' => $business->id, 'role' => 'receptionist']);

        $response = $this->actingAs($receptionist)->post('/team/invite', [
            'email' => 'hack@beta.com',
            'role' => 'doctor',
        ]);

        $response->assertStatus(403);
    }

    public function test_invitation_business_id_from_inviter_not_payload()
    {
        $businessReal = Business::create(['name' => 'Real Business', 'slug' => 'real', 'vertical' => 'health']);
        $businessOther = Business::create(['name' => 'Other Business', 'slug' => 'other', 'vertical' => 'health']);

        $owner = User::factory()->create(['business_id' => $businessReal->id, 'role' => 'owner']);

        // Intentar pasar un business_id malicioso en el payload
        $this->actingAs($owner)->post('/team/invite', [
            'business_id' => $businessOther->id,
            'email' => 'staff@real.com',
            'role' => 'receptionist',
        ]);

        $invitation = Invitation::withoutGlobalScope(BusinessScope::class)->where('email', 'staff@real.com')->first();
        $this->assertNotNull($invitation);
        $this->assertEquals($businessReal->id, $invitation->business_id);
    }

    public function test_cannot_accept_expired_or_used_invitation()
    {
        $business = Business::create(['name' => 'Consultorio Gamma', 'slug' => 'gamma', 'vertical' => 'health']);

        BusinessContext::runInContext($business, fn () => Invitation::create([
            'business_id' => $business->id,
            'email' => 'old@gamma.com',
            'role' => 'receptionist',
            'token' => 'expired_token',
            'expires_at' => now()->subHour(),
        ]));

        $user = User::factory()->withoutBusiness()->create(['email' => 'old@gamma.com']);

        $response = $this->actingAs($user)->get('/invitations/accept/expired_token');
        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->component('Team/InvitationError'));

        $this->assertNull($user->fresh()->business_id);
    }

    public function test_re_inviting_same_email_updates_not_duplicates()
    {
        $business = Business::create(['name' => 'Consultorio Delta', 'slug' => 'delta', 'vertical' => 'health']);
        $owner = User::factory()->create(['business_id' => $business->id, 'role' => 'owner']);

        $this->actingAs($owner)->post('/team/invite', [
            'email' => 'rep@delta.com',
            'role' => 'receptionist',
        ]);

        $inv1 = Invitation::withoutGlobalScope(BusinessScope::class)->where('email', 'rep@delta.com')->first();
        $this->assertNotNull($inv1);
        $token1 = $inv1->token;

        // Re-invitar al mismo correo
        $this->actingAs($owner)->post('/team/invite', [
            'email' => 'rep@delta.com',
            'role' => 'doctor',
        ]);

        $this->assertEquals(1, Invitation::withoutGlobalScope(BusinessScope::class)->where('email', 'rep@delta.com')->count());

        $updatedInvite = Invitation::withoutGlobalScope(BusinessScope::class)->where('email', 'rep@delta.com')->first();
        $this->assertEquals('doctor', $updatedInvite->role);
        $this->assertNotEquals($token1, $updatedInvite->token);
    }

    public function test_collaborator_only_sees_own_business_data()
    {
        $businessA = Business::create(['name' => 'Consultorio A', 'slug' => 'biz-a', 'vertical' => 'health']);
        $businessB = Business::create(['name' => 'Consultorio B', 'slug' => 'biz-b', 'vertical' => 'health']);

        $userA = User::factory()->create(['business_id' => $businessA->id, 'role' => 'receptionist']);

        BusinessContext::runInContext($businessA, fn () => Invitation::create([
            'business_id' => $businessA->id,
            'email' => 'invA@biz.com',
            'token' => 'tokA',
            'expires_at' => now()->addDay(),
        ]));

        BusinessContext::runInContext($businessB, fn () => Invitation::create([
            'business_id' => $businessB->id,
            'email' => 'invB@biz.com',
            'token' => 'tokB',
            'expires_at' => now()->addDay(),
        ]));

        // Intentar acceder a la lista del equipo del negocio A siendo recepcionista -> 403
        $response = $this->actingAs($userA)->get('/team');

        $response->assertStatus(403);
    }
}
