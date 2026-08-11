<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class TelegramChannelController extends Controller
{
    /**
     * Muestra la vista de configuración y estado de conexión con Telegram para el usuario.
     */
    public function show(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Channels/Telegram', [
            'status' => [
                'is_connected' => ! empty($user?->telegram_chat_id),
                'telegram_chat_id' => $user?->telegram_chat_id,
            ],
            'bot_username' => config('services.telegram.bot_username', 'UaldoBot'),
        ]);
    }

    /**
     * Genera un código de conexión único de 6 caracteres válido por 15 minutos.
     */
    public function generateCode(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! $user) {
            abort(403);
        }

        // Generar código alfanumérico en mayúsculas
        $code = strtoupper(Str::random(6));

        // Almacenar relación código => user_id por 15 minutos (900s)
        Cache::put("telegram_connect_{$code}", $user->id, 900);

        return redirect()->back()->with([
            'connect_code' => $code,
            'expires_in_minutes' => 15,
            'message' => "Tu código de conexión es {$code}. Envía '/connect {$code}' al bot de Telegram.",
        ]);
    }
}
