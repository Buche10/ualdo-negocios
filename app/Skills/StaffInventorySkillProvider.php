<?php

namespace App\Skills;

use App\Models\InventoryItem;
use App\Models\User;
use App\Services\ApprovalGate;
use App\Services\BusinessContext;
use App\Services\StaffInventoryService;
use Prism\Prism\Tool;

class StaffInventorySkillProvider
{
    public function __construct(
        protected StaffInventoryService $staffInventoryService
    ) {}

    /**
     * Get Prism tools for staff inventory management.
     *
     * @return array<int, Tool>
     */
    public function getTools(?User $user = null): array
    {
        return [
            $this->searchInventoryItemTool(),
            $this->reportInventoryMovementTool($user),
        ];
    }

    protected function searchInventoryItemTool(): Tool
    {
        return (new Tool)
            ->as('search_inventory_item')
            ->for('Busca un producto o insumo en el inventario por nombre o código para desambiguar.')
            ->withStringParameter('query', 'Nombre parcial o SKU del producto (ej: piña, bondiola, PRD-001)', true)
            ->using(function (string $query) {
                $business = BusinessContext::get();
                if (! $business) {
                    return json_encode(['status' => 'error', 'message' => 'Sin contexto de negocio activo.'], JSON_UNESCAPED_UNICODE);
                }

                $items = InventoryItem::where(function ($q) use ($query) {
                    $q->where('name', 'like', "%{$query}%")
                        ->orWhere('attributes->sku', 'like', "%{$query}%");
                })
                    ->take(10)
                    ->get()
                    ->map(fn (InventoryItem $i) => [
                        'id' => $i->id,
                        'name' => $i->name,
                        'sku' => $i->attributes['sku'] ?? 'N/A',
                        'stock' => $i->stock,
                        'unit' => $i->attributes['unit'] ?? 'unit',
                    ]);

                if ($items->isEmpty()) {
                    return json_encode(['status' => 'not_found', 'message' => "No se encontró el producto '{$query}' en el inventario."], JSON_UNESCAPED_UNICODE);
                }

                return json_encode([
                    'status' => 'success',
                    'count' => $items->count(),
                    'items' => $items->toArray(),
                ], JSON_UNESCAPED_UNICODE);
            });
    }

    protected function reportInventoryMovementTool(?User $user = null): Tool
    {
        return (new Tool)
            ->as('report_inventory_movement')
            ->for('Propone un movimiento de inventario (entrada, consumo/salida o conteo) solicitando confirmación del usuario.')
            ->withStringParameter('item_name', 'Nombre o SKU del producto', true)
            ->withNumberParameter('quantity', 'Cantidad a registrar (en la unidad del producto)', true)
            ->withStringParameter('kind', 'Tipo de movimiento: in (entrada), out (consumo/salida), count (conteo físico final)', true)
            ->withStringParameter('location', 'Ubicación opcional (kitchen, warehouse, lounge)', false)
            ->using(function (string $item_name, float $quantity, string $kind, ?string $location = 'kitchen') use ($user) {
                $business = BusinessContext::get();
                if (! $business) {
                    return json_encode(['status' => 'error', 'message' => 'Sin contexto de negocio activo.'], JSON_UNESCAPED_UNICODE);
                }

                $item = $this->staffInventoryService->resolveProduct($item_name);
                if (! $item) {
                    return json_encode([
                        'status' => 'not_found',
                        'message' => "El producto '{$item_name}' no existe en el catálogo espejado del negocio.",
                    ], JSON_UNESCAPED_UNICODE);
                }

                $unit = $item->attributes['unit'] ?? 'unit';
                $kindDesc = match ($kind) {
                    'in', 'input' => 'Entrada',
                    'out', 'output' => 'Consumo / Salida',
                    'count' => 'Conteo Físico',
                    default => 'Ajuste',
                };

                /** @var User|null $staffUser */
                $staffUser = $user ?? $business->users()->first();
                if (! $staffUser) {
                    return json_encode(['status' => 'error', 'message' => 'No hay usuario asignado para generar la confirmación.'], JSON_UNESCAPED_UNICODE);
                }

                $payload = [
                    'item_id' => $item->id,
                    'quantity' => $quantity,
                    'kind' => $kind,
                    'location' => $location ?: 'kitchen',
                    'staff_id' => $staffUser->id,
                    'description' => "{$kindDesc} de {$quantity} {$unit} de '{$item->name}'",
                ];

                /** @var ApprovalGate $approvalGate */
                $approvalGate = app(ApprovalGate::class);
                $pendingAction = $approvalGate->createPendingAction($staffUser, $business, 'inventory_adjustment', $payload, 30);

                return json_encode([
                    'status' => 'requires_confirmation',
                    'action_id' => $pendingAction->id,
                    'token' => $pendingAction->token,
                    'item_name' => $item->name,
                    'quantity' => $quantity,
                    'unit' => $unit,
                    'current_stock' => $item->stock,
                    'confirmation_prompt' => "📋 ¿Confirmar {$kindDesc} de {$quantity} {$unit} de {$item->name}? (Stock actual: {$item->stock} {$unit})",
                ], JSON_UNESCAPED_UNICODE);
            });
    }
}
