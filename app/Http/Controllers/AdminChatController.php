<?php

namespace App\Http\Controllers;

use App\Models\InventoryItem;
use App\Services\ApprovalGate;
use App\Services\BusinessContext;
use App\Services\UaldoAdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminChatController extends Controller
{
    protected UaldoAdminService $adminService;

    protected ApprovalGate $approvalGate;

    public function __construct(UaldoAdminService $adminService, ApprovalGate $approvalGate)
    {
        $this->adminService = $adminService;
        $this->approvalGate = $approvalGate;
    }

    /**
     * Process incoming admin chat message.
     */
    public function chat(Request $request): JsonResponse
    {
        $request->validate([
            'message' => ['nullable', 'string', 'max:1000'],
        ]);

        $user = $request->user();
        if (! $user || ! $user->business_id) {
            return response()->json(['error' => 'Usuario o negocio no especificado.'], 403);
        }

        $reply = $this->adminService->processMessage($user, (string) $request->input('message'));

        return response()->json([
            'reply' => $reply,
        ]);
    }

    /**
     * Confirm a pending approval action token.
     */
    public function approve(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string', 'max:64'],
        ]);

        $user = $request->user();
        if (! $user) {
            return response()->json(['error' => 'No autenticado.'], 401);
        }

        $result = $this->approvalGate->confirmAction(
            token: $request->input('token'),
            user: $user,
            executor: function (string $action, array $payload, $user) {
                return BusinessContext::runInContext($user->business, function () use ($action, $payload, $user) {
                    if ($action === 'delete_inventory_item') {
                        $itemName = $payload[0] ?? '';
                        /** @var InventoryItem|null $item */
                        $item = InventoryItem::where('business_id', $user->business_id)
                            ->where('name', 'like', "%{$itemName}%")
                            ->first();
                        if ($item) {
                            $name = $item->name;
                            $item->delete();

                            return "Item '{$name}' eliminado permanentemente.";
                        }

                        return "No se encontró el item '{$itemName}'.";
                    }

                    if ($action === 'update_price') {
                        $itemName = $payload[0] ?? '';
                        $newPrice = (float) ($payload[1] ?? 0);
                        /** @var InventoryItem|null $item */
                        $item = InventoryItem::where('business_id', $user->business_id)
                            ->where('name', 'like', "%{$itemName}%")
                            ->first();
                        if ($item) {
                            $oldPrice = $item->price;
                            $cleanPrice = max(0.0, $newPrice);
                            $item->update(['price' => $cleanPrice]);

                            return "Precio de '{$item->name}' actualizado de \${$oldPrice} a \${$cleanPrice}.";
                        }

                        return "No se encontró el item '{$itemName}'.";
                    }

                    if ($action === 'update_ai_persona') {
                        $personaDescription = $payload[0] ?? '';
                        $business = $user->business;
                        if ($business) {
                            $cleanPersona = mb_substr(trim($personaDescription), 0, 300);
                            $settings = $business->settings ?? [];
                            $settings['ai_persona'] = $cleanPersona;
                            $business->update(['settings' => $settings]);

                            return "Personalidad de Ualdo actualizada a: {$cleanPersona}";
                        }
                    }

                    if ($action === 'update_business_hours') {
                        $start = $payload[0] ?? '09:00';
                        $end = $payload[1] ?? '18:00';
                        $business = $user->business;
                        if ($business && $start < $end) {
                            $business->update(['business_hours_start' => $start, 'business_hours_end' => $end]);

                            return "Horario comercial actualizado a {$start} - {$end}.";
                        }
                    }

                    if ($action === 'update_working_days') {
                        $days = (array) ($payload[0] ?? []);
                        $business = $user->business;
                        if ($business && ! empty($days)) {
                            $business->update(['working_days' => array_values($days)]);

                            return 'Días laborables actualizados.';
                        }
                    }

                    return "Acción '{$action}' ejecutada.";
                });
            }
        );

        if (! $result['success']) {
            return response()->json($result, 400);
        }

        return response()->json($result);
    }

    /**
     * Cancel a pending action token.
     */
    public function cancel(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string', 'max:64'],
        ]);

        $user = $request->user();
        if (! $user) {
            return response()->json(['error' => 'No autenticado.'], 401);
        }

        $cancelled = $this->approvalGate->cancelAction($request->input('token'), $user);

        if (! $cancelled) {
            return response()->json([
                'success' => false,
                'message' => 'No se pudo cancelar la acción (token inválido o ya procesado).',
            ], 400);
        }

        return response()->json([
            'success' => true,
            'message' => 'Acción cancelada exitosamente.',
        ]);
    }
}
