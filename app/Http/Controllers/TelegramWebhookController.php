<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\InventoryItem;
use App\Models\User;
use App\Services\ApprovalGate;
use App\Services\BusinessContext;
use App\Services\StaffInventoryService;
use App\Services\TelegramService;
use App\Services\UaldoStaffService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class TelegramWebhookController extends Controller
{
    /**
     * Maneja los webhooks entrantes de Telegram usando Nutgram para vinculación, mensajes y callback_query de aprobación.
     */
    public function handle(Request $request, UaldoStaffService $staffService, TelegramService $telegramService, ApprovalGate $approvalGate): JsonResponse
    {
        // Validar secret token si está configurado
        $secretToken = config('services.telegram.secret_token');
        if (! empty($secretToken)) {
            $headerToken = $request->header('X-Telegram-Bot-Api-Secret-Token');
            if ($headerToken !== $secretToken) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }
        }

        $update = $request->all();
        $updateId = $update['update_id'] ?? null;

        // Dedup por update_id
        if ($updateId) {
            $cacheKey = "telegram_update_{$updateId}";
            if (Cache::has($cacheKey)) {
                return response()->json(['status' => 'duplicate_ignored']);
            }
            Cache::put($cacheKey, true, 86400);
        }

        // Manejo de callback_query (Botones Inline: Confirmar/Cancelar)
        $callbackQuery = $update['callback_query'] ?? null;
        if ($callbackQuery) {
            $chatId = (string) ($callbackQuery['message']['chat']['id'] ?? $callbackQuery['from']['id'] ?? '');
            $callbackData = trim($callbackQuery['data'] ?? '');

            if (! empty($chatId) && ! empty($callbackData)) {
                $user = User::where('telegram_chat_id', $chatId)->first();
                if (! $user) {
                    $telegramService->notifyStaff('🔒 No tienes autorización para responder a esta acción.', $chatId);

                    return response()->json(['status' => 'unrecognized_chat_id']);
                }

                if (str_starts_with($callbackData, 'approve:')) {
                    $token = str_replace('approve:', '', $callbackData);
                    $result = $approvalGate->confirmAction(
                        token: $token,
                        user: $user,
                        executor: function (string $action, array $payload, User $u) {
                            if ($action === 'inventory_adjustment') {
                                $item = InventoryItem::withoutGlobalScopes()->find($payload['item_id'] ?? null);
                                if (! $item) {
                                    return 'Error: Ítem de inventario no encontrado.';
                                }

                                $res = app(StaffInventoryService::class)->register(
                                    staff: $u,
                                    item: $item,
                                    quantity: (float) ($payload['quantity'] ?? 0),
                                    kind: (string) ($payload['kind'] ?? 'in'),
                                    location: (string) ($payload['location'] ?? 'kitchen')
                                );

                                return $res['message'];
                            }

                            return "Acción '{$action}' ejecutada por confirmación en Telegram.";
                        }
                    );

                    $msg = $result['success']
                        ? "✅ {$result['message']}"
                        : "❌ {$result['message']}";

                    $telegramService->notifyStaff($msg, $chatId);

                    return response()->json(['status' => 'callback_processed', 'result' => $result]);
                }

                if (str_starts_with($callbackData, 'cancel:')) {
                    $token = str_replace('cancel:', '', $callbackData);
                    $cancelled = $approvalGate->cancelAction($token, $user);

                    $msg = $cancelled
                        ? '🚫 La acción pendiente fue cancelada exitosamente.'
                        : '❌ No se pudo cancelar la acción (inválida o expirada).';

                    $telegramService->notifyStaff($msg, $chatId);

                    return response()->json(['status' => 'callback_cancelled']);
                }
            }

            return response()->json(['status' => 'callback_ignored']);
        }

        $message = $update['message'] ?? null;
        if (! $message) {
            return response()->json(['status' => 'ignored']);
        }

        $chatId = (string) ($message['chat']['id'] ?? '');
        $text = trim($message['text'] ?? '');

        if (empty($chatId) || empty($text)) {
            return response()->json(['status' => 'ignored']);
        }

        // Manejar comando /connect <code>
        if (str_starts_with($text, '/connect')) {
            $attemptsKey = "telegram_connect_attempts_{$chatId}";
            $attempts = (int) Cache::get($attemptsKey, 0);

            if ($attempts >= 5) {
                $telegramService->notifyStaff('⛔ Demasiados intentos fallidos. Has sido bloqueado por 15 minutos por seguridad.', $chatId);

                return response()->json(['status' => 'rate_limited_chat'], 429);
            }

            $parts = explode(' ', $text);
            $code = strtoupper(trim($parts[1] ?? ''));

            if (empty($code)) {
                Cache::put($attemptsKey, $attempts + 1, 900);
                $telegramService->notifyStaff('⚠️ Por favor envía el comando con tu código de conexión. Ejemplo: `/connect 123456`', $chatId);

                return response()->json(['status' => 'code_missing']);
            }

            $userId = Cache::pull("telegram_connect_{$code}");

            if (! $userId) {
                Cache::put($attemptsKey, $attempts + 1, 900);
                $telegramService->notifyStaff('❌ El código de conexión es inválido o ha expirado. Genera un nuevo código desde tu panel de Ualdo.', $chatId);

                return response()->json(['status' => 'code_invalid']);
            }

            Cache::forget($attemptsKey);

            $user = User::find($userId);
            if ($user) {
                $user->update(['telegram_chat_id' => $chatId]);
                /** @var Business|null $business */
                $business = $user->business;
                $businessName = $business?->name ?? 'Ualdo';
                $telegramService->notifyStaff("✅ ¡Hola {$user->name}! Tu cuenta de Telegram ha sido conectada exitosamente a {$businessName}. Recibirás alertas y tareas aquí.", $chatId);

                return response()->json(['status' => 'connected']);
            }
        }

        // Si el chat_id no está registrado en ningún usuario, solicitar vinculación
        $user = User::where('telegram_chat_id', $chatId)->first();
        if (! $user) {
            $telegramService->notifyStaff('🔒 No reconozco tu usuario. Por favor genera un código de conexión desde tu panel de Ualdo e ingresa `/connect <código>`.', $chatId);

            return response()->json(['status' => 'unrecognized_chat_id']);
        }

        // Usuario registrado -> Procesar mensaje a través de UaldoStaffService
        try {
            $staffService->handleStaffMessage($user, $text, $chatId);
        } finally {
            BusinessContext::forget();
        }

        return response()->json(['status' => 'processed']);
    }
}
