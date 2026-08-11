<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Services\WhatsAppService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ChannelController extends Controller
{
    /**
     * Muestra la pantalla de configuración del canal WhatsApp del negocio.
     */
    public function show(Request $request): Response
    {
        $user = $request->user();
        if (! $user?->isOwner()) {
            abort(403, 'Solo el propietario del negocio puede administrar los canales de comunicación.');
        }

        /** @var Business|null $business */
        $business = $user->business;

        $hasToken = $business ? $business->has_whatsapp_token : false;
        $rawToken = $business ? (string) $business->whatsapp_access_token : '';
        $maskedToken = $hasToken && strlen($rawToken) >= 4
            ? '••••••••'.substr($rawToken, -4)
            : ($hasToken ? '••••••••' : '');

        return Inertia::render('Channels/WhatsApp', [
            'channel' => [
                'whatsapp_phone_number_id' => $business?->whatsapp_phone_number_id ?? '',
                'whatsapp_phone_number' => $business?->whatsapp_phone_number ?? '',
                'has_token' => $hasToken,
                'masked_token' => $maskedToken,
            ],
        ]);
    }

    /**
     * Actualiza las credenciales del canal WhatsApp del negocio.
     */
    public function updateWhatsApp(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (! $user?->isOwner()) {
            abort(403, 'Solo el propietario del negocio puede modificar credenciales de comunicación.');
        }

        /** @var Business|null $business */
        $business = $user->business;

        if (! $business) {
            abort(403, 'No tienes un negocio configurado.');
        }

        $validated = $request->validate([
            'whatsapp_phone_number_id' => 'required|string|max:255',
            'whatsapp_phone_number' => ['nullable', 'string', 'phone:INTERNATIONAL'],
            'whatsapp_access_token' => 'nullable|string|max:1000',
        ]);

        $business->whatsapp_phone_number_id = $validated['whatsapp_phone_number_id'];
        $business->whatsapp_phone_number = $validated['whatsapp_phone_number'] ?? null;

        // Solo actualizar el token si no es el placeholder de enmascaramiento
        if (isset($validated['whatsapp_access_token']) && ! str_contains($validated['whatsapp_access_token'], '••••')) {
            $token = trim($validated['whatsapp_access_token']);
            $business->whatsapp_access_token = ! empty($token) ? $token : null;
        }

        $business->save();

        return redirect()->back()->with('message', 'Credenciales de WhatsApp guardadas con éxito.');
    }

    /**
     * Envía un mensaje de prueba utilizando el número del negocio.
     */
    public function testWhatsApp(Request $request, WhatsAppService $whatsAppService): RedirectResponse
    {
        $user = $request->user();
        if (! $user?->isOwner()) {
            abort(403, 'Solo el propietario del negocio puede realizar pruebas de comunicación.');
        }

        /** @var Business|null $business */
        $business = $user->business;

        if (! $business) {
            abort(403, 'No tienes un negocio configurado.');
        }

        $validated = $request->validate([
            'phone_number' => ['required', 'string', 'phone:INTERNATIONAL'],
        ]);

        $testMessage = "👋 ¡Hola! Este es un mensaje de prueba exitoso enviado desde {$business->name} usando Ualdo AI.";
        $res = $whatsAppService->sendText($validated['phone_number'], $testMessage);

        if (! $res || isset($res['error'])) {
            $errMsg = $res['error']['message'] ?? 'Fallo al conectar con WhatsApp API. Revisa tu Phone ID y Token.';

            return redirect()->back()->withErrors(['test' => $errMsg]);
        }

        return redirect()->back()->with('message', 'Mensaje de prueba enviado con éxito a '.$validated['phone_number']);
    }
}
