<?php

namespace App\Http\Controllers;

use App\Models\Business;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class CrmSsoController extends Controller
{
    /**
     * Genera un token firmado de SSO de corta duración (≤60s) con nonce anti-replay para acceder al CRM.
     */
    public function sso(Request $request): RedirectResponse
    {
        $user = $request->user();
        /** @var Business|null $business */
        $business = $user?->business;

        if (! $business || empty($business->crm_workspace_id)) {
            return redirect()->back()->withErrors([
                'sso' => 'El espacio CRM aún se está aprovisionando. Por favor intenta de nuevo en unos segundos.',
            ]);
        }

        $crmBaseUrl = config('services.crm.url', 'https://crm.nexoteams.com');
        $nonce = Str::random(16);

        // Generar URL firmada de 60 segundos
        $ssoUrl = URL::temporarySignedRoute(
            'sso.crm.verify',
            now()->addSeconds(60),
            [
                'user_id' => $user->id,
                'business_id' => $business->id,
                'workspace_id' => $business->crm_workspace_id,
                'nonce' => $nonce,
            ]
        );

        $targetUrl = "{$crmBaseUrl}/auth/sso?token=".urlencode($ssoUrl);

        return redirect()->away($targetUrl);
    }

    /**
     * Endpoint interno para verificar la validez del token firmado de SSO y consumir el nonce.
     */
    public function verify(Request $request): JsonResponse
    {
        if (! $request->hasValidSignature()) {
            return response()->json(['error' => 'Token SSO expirado o firma inválida'], 401);
        }

        $nonce = (string) $request->query('nonce');
        if (empty($nonce) || Cache::has("sso_nonce_{$nonce}")) {
            return response()->json(['error' => 'El token SSO ya ha sido utilizado (replay attack)'], 401);
        }

        // Invalidar nonce por 120 segundos
        Cache::put("sso_nonce_{$nonce}", true, 120);

        return response()->json([
            'status' => 'success',
            'user_id' => $request->query('user_id'),
            'business_id' => $request->query('business_id'),
            'workspace_id' => $request->query('workspace_id'),
        ]);
    }
}
