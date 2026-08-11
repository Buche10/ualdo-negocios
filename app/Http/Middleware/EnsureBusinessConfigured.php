<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureBusinessConfigured
{
    /**
     * Garantiza que todo usuario autenticado y verificado sin negocio asociado sea redirigido
     * obligatoriamente a la pantalla de onboarding por chat (salvo si está aceptando una invitación).
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->hasVerifiedEmail() && ! $user->business_id) {
            if (! $request->routeIs('onboarding', 'onboarding.store', 'invitations.accept', 'logout')) {
                return redirect()->route('onboarding');
            }
        }

        return $next($request);
    }
}
