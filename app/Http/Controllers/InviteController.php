<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\Invitation;
use App\Scopes\BusinessScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class InviteController extends Controller
{
    /**
     * Muestra la lista de invitaciones y miembros del equipo.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        if (! $user?->isOwner()) {
            abort(403, 'Solo el propietario del negocio puede administrar invitaciones del equipo.');
        }

        /** @var Business|null $business */
        $business = $user?->business;

        $invitations = Invitation::where('business_id', $user->business_id)
            ->latest()
            ->get();

        $teamMembers = $business?->users()->get(['id', 'name', 'email', 'role', 'created_at']) ?? [];

        return Inertia::render('Team/Index', [
            'invitations' => $invitations,
            'teamMembers' => $teamMembers,
        ]);
    }

    /**
     * Envía o actualiza una invitación para unirse al negocio con un rol específico.
     */
    public function send(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! $user || ! $user->isOwner()) {
            abort(403, 'Solo el propietario del negocio puede invitar colaboradores.');
        }

        $validated = $request->validate([
            'email' => 'required|email|max:255',
            'role' => 'required|string|in:owner,receptionist,doctor',
        ]);

        $businessId = $user->business_id;

        // Re-inviting same email updates record instead of duplicating
        $invitation = Invitation::updateOrCreate(
            [
                'business_id' => $businessId,
                'email' => strtolower(trim($validated['email'])),
            ],
            [
                'role' => $validated['role'],
                'token' => Str::random(32),
                'expires_at' => now()->addHours(48),
                'accepted_at' => null,
                'created_by' => $user->id,
            ]
        );

        $inviteUrl = route('invitations.accept', ['token' => $invitation->token]);

        return redirect()->back()->with([
            'message' => "Invitación enviada con éxito para {$invitation->email}.",
            'invite_url' => $inviteUrl,
        ]);
    }

    /**
     * Procesa la aceptación de una invitación por token firmado.
     */
    public function accept(Request $request, string $token): RedirectResponse|Response
    {
        $invitation = Invitation::withoutGlobalScope(BusinessScope::class)
            ->where('token', $token)
            ->first();

        if (! $invitation) {
            abort(404, 'La invitación no existe o el enlace es inválido.');
        }

        if ($invitation->isExpired()) {
            return Inertia::render('Team/InvitationError', [
                'error' => 'La invitación ha expirado. Solicita al propietario una nueva invitación.',
            ]);
        }

        if ($invitation->isAccepted()) {
            return Inertia::render('Team/InvitationError', [
                'error' => 'Esta invitación ya fue utilizada anteriormente.',
            ]);
        }

        $user = $request->user();

        if (! $user) {
            // Guardar token en sesión para completar tras login/registro
            session(['pending_invitation_token' => $token]);

            return redirect()->route('login');
        }

        // 1. Validar que el email del usuario autenticado coincida con el email invitado
        if (strtolower(trim($user->email)) !== strtolower(trim($invitation->email))) {
            return Inertia::render('Team/InvitationError', [
                'error' => "Esta invitación fue emitida para '{$invitation->email}'. Estás autenticado como '{$user->email}'. Por favor inicia sesión con la cuenta correcta.",
            ]);
        }

        // 2. Prevenir sobrescritura si el usuario ya pertenece a otro negocio diferente
        if ($user->business_id && $user->business_id !== $invitation->business_id) {
            return Inertia::render('Team/InvitationError', [
                'error' => 'Ya perteneces a un negocio activo. No puedes unirte a otro negocio sin desvincularte previamente.',
            ]);
        }

        // Vincular usuario al business de la invitación y asignar rol
        $user->update([
            'business_id' => $invitation->business_id,
        ]);
        $user->assignRole($invitation->role);

        $invitation->update(['accepted_at' => now()]);

        return redirect()->route('dashboard')->with('message', '¡Te has unido exitosamente al equipo!');
    }
}
