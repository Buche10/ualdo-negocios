<?php

namespace App\Http\Middleware;

use App\Models\Business;
use App\Services\BusinessContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetBusinessContext
{
    /**
     * Resuelve el negocio (tenant) para peticiones web autenticadas a partir del
     * usuario logueado, para que el scope multi-tenant fail-closed funcione en el panel.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->business_id) {
            /** @var Business|null $business */
            $business = $user->business;
            BusinessContext::set($business);
        }

        try {
            return $next($request);
        } finally {
            // Evita fugas de contexto estático entre peticiones (workers persistentes/Octane).
            BusinessContext::clear();
        }
    }
}
