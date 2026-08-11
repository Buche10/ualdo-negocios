<?php

namespace App\Services\AdminTools;

use App\Models\InventoryItem;
use App\Models\User;
use App\Services\BusinessContext;
use App\Services\InventoryService;
use Cknow\Money\Money;
use Prism\Prism\Tool;

class AdminWriteTools
{
    /**
     * Get array of Prism tools for admin write operations.
     *
     * @return array<int, Tool>
     */
    public function getTools(?User $user = null): array
    {
        return [
            $this->addInventoryItemTool($user),
            $this->updateStockTool($user),
            $this->restockItemTool($user),
            $this->deleteInventoryItemTool($user),
            $this->updatePriceTool($user),
        ];
    }

    /**
     * Tool: add_inventory_item (Direct write, low risk)
     */
    public function addInventoryItemTool(?User $user = null): Tool
    {
        return AdminTool::make(
            'add_inventory_item',
            'Agrega un nuevo insumo, producto o servicio al inventario del negocio.'
        )
            ->withStringParameter('name', 'Nombre del item/insumo/servicio', true)
            ->withNumberParameter('stock', 'Cantidad inicial en stock', true)
            ->withNumberParameter('min_stock', 'Stock mínimo de alerta', false)
            ->withNumberParameter('price', 'Precio en USD', false)
            ->withStringParameter('unit', 'Unidad de medida (ej. unidad, caja, frasco)', false)
            ->withStringParameter('type', 'Tipo: supply (insumo) o service (servicio)', false)
            ->withStringParameter('supplier', 'Nombre del proveedor (opcional)', false)
            ->using(function (string $name = '', float $stock = 0, ?float $min_stock = 5, ?float $price = 0.0, ?string $unit = 'unidad', ?string $type = 'supply', ?string $supplier = null) use ($user) {
                $business = BusinessContext::get();
                if (! $business) {
                    return json_encode(['status' => 'error', 'message' => 'No hay negocio activo en contexto.'], JSON_UNESCAPED_UNICODE);
                }

                if (empty(trim($name))) {
                    return json_encode(['status' => 'error', 'message' => 'El nombre del item no puede estar vacío.'], JSON_UNESCAPED_UNICODE);
                }

                $cleanPriceCents = (int) round(max(0.0, (float) ($price ?? 0.0)) * 100);
                $initialStock = max(0, (int) $stock);

                $item = InventoryItem::create([
                    'business_id' => $business->id,
                    'name' => trim($name),
                    'stock' => 0,
                    'min_stock' => max(0, (int) ($min_stock ?? 5)),
                    'price' => Money::USD($cleanPriceCents),
                    'type' => in_array($type, ['supply', 'service']) ? $type : 'supply',
                    'supplier' => $supplier,
                    'attributes' => ['unit' => $unit ?? 'unidad'],
                ]);

                if ($initialStock > 0) {
                    app(InventoryService::class)->adjustStock(
                        item: $item,
                        delta: $initialStock,
                        type: 'in',
                        reason: 'Stock inicial',
                        user: $user
                    );
                    $item->refresh();
                }

                return json_encode([
                    'status' => 'success',
                    'message' => "Item '{$item->name}' agregado exitosamente al inventario.",
                    'item' => $item->toLlmArray(),
                ], JSON_UNESCAPED_UNICODE);
            })
            ->build($user);
    }

    /**
     * Tool: update_stock (Direct write, low risk, via InventoryService)
     */
    public function updateStockTool(?User $user = null): Tool
    {
        return AdminTool::make(
            'update_stock',
            'Actualiza el stock o cantidad disponible de un insumo/producto de forma atómica y registra la transacción.'
        )
            ->withStringParameter('item_name', 'Nombre del item en el inventario', true)
            ->withNumberParameter('quantity', 'Nueva cantidad de stock o ajuste incremental', true)
            ->withStringParameter('mode', 'Modo: set (establecer valor exacto) o add (sumar a lo existente)', false)
            ->using(function (string $item_name = '', float $quantity = 0, ?string $mode = 'set') use ($user) {
                $business = BusinessContext::get();
                if (! $business) {
                    return json_encode(['status' => 'error', 'message' => 'No hay negocio activo en contexto.'], JSON_UNESCAPED_UNICODE);
                }

                /** @var InventoryItem|null $item */
                $item = InventoryItem::where('business_id', $business->id)
                    ->where('name', 'like', "%{$item_name}%")
                    ->first();

                if (! $item) {
                    return json_encode([
                        'status' => 'error',
                        'message' => "No se encontró ningún producto con el nombre '{$item_name}' en tu inventario.",
                    ], JSON_UNESCAPED_UNICODE);
                }

                $oldStock = $item->stock;
                if ($mode === 'add') {
                    $delta = (int) $quantity;
                } else {
                    $delta = (int) $quantity - $oldStock;
                }

                $transaction = app(InventoryService::class)->adjustStock(
                    item: $item,
                    delta: $delta,
                    type: 'adjust',
                    reason: 'Ajuste manual de stock',
                    user: $user
                );

                return json_encode([
                    'status' => 'success',
                    'message' => "Stock de '{$item->name}' actualizado de {$oldStock} a {$transaction->balance_after}.",
                    'item_id' => $item->id,
                    'old_stock' => $oldStock,
                    'new_stock' => $transaction->balance_after,
                ], JSON_UNESCAPED_UNICODE);
            })
            ->build($user);
    }

    /**
     * Tool: restock_item (Direct write, low risk, stock replenishment)
     */
    public function restockItemTool(?User $user = null): Tool
    {
        return AdminTool::make(
            'restock_item',
            'Registra el reabastecimiento o reposición de stock de un producto o insumo indicando opcionalmente el proveedor.'
        )
            ->withStringParameter('item_name', 'Nombre del item a reabastecer', true)
            ->withNumberParameter('quantity', 'Cantidad que ingresa al inventario', true)
            ->withStringParameter('supplier', 'Nombre del proveedor (opcional)', false)
            ->using(function (string $item_name = '', float $quantity = 0, ?string $supplier = null) use ($user) {
                $business = BusinessContext::get();
                if (! $business) {
                    return json_encode(['status' => 'error', 'message' => 'No hay negocio activo en contexto.'], JSON_UNESCAPED_UNICODE);
                }

                $qty = max(1, (int) $quantity);
                /** @var InventoryItem|null $item */
                $item = InventoryItem::where('business_id', $business->id)
                    ->where('name', 'like', "%{$item_name}%")
                    ->first();

                if (! $item) {
                    return json_encode([
                        'status' => 'error',
                        'message' => "No se encontró ningún producto con el nombre '{$item_name}' en tu inventario.",
                    ], JSON_UNESCAPED_UNICODE);
                }

                if ($supplier && empty($item->supplier)) {
                    $item->update(['supplier' => $supplier]);
                }

                $reason = $supplier ? "Reposición de stock (Proveedor: {$supplier})" : 'Reposición de stock';
                $tx = app(InventoryService::class)->adjustStock(
                    item: $item,
                    delta: $qty,
                    type: 'restock',
                    reason: $reason,
                    user: $user
                );

                return json_encode([
                    'status' => 'success',
                    'message' => "Se registraron {$qty} unidades de '{$item->name}'. Stock actual: {$tx->balance_after}.",
                    'item_id' => $item->id,
                    'new_stock' => $tx->balance_after,
                ], JSON_UNESCAPED_UNICODE);
            })
            ->build($user);
    }

    /**
     * Tool: delete_inventory_item (High risk, behind ApprovalGate)
     */
    public function deleteInventoryItemTool(?User $user = null): Tool
    {
        return AdminTool::make(
            'delete_inventory_item',
            'Elimina de forma permanente un insumo, producto o servicio del inventario.'
        )
            ->withStringParameter('item_name', 'Nombre del item a eliminar', true)
            ->requiresApproval(true)
            ->using(function (string $item_name = '') {
                $business = BusinessContext::get();
                if (! $business) {
                    return json_encode(['status' => 'error', 'message' => 'No hay negocio activo en contexto.'], JSON_UNESCAPED_UNICODE);
                }

                /** @var InventoryItem|null $item */
                $item = InventoryItem::where('business_id', $business->id)
                    ->where('name', 'like', "%{$item_name}%")
                    ->first();

                if (! $item) {
                    return json_encode(['status' => 'error', 'message' => "No se encontró el item '{$item_name}'."], JSON_UNESCAPED_UNICODE);
                }

                $deletedName = $item->name;
                $item->delete();

                return json_encode([
                    'status' => 'success',
                    'message' => "Item '{$deletedName}' eliminado permanentemente del inventario.",
                ], JSON_UNESCAPED_UNICODE);
            })
            ->build($user);
    }

    /**
     * Tool: update_price (High risk, behind ApprovalGate)
     */
    public function updatePriceTool(?User $user = null): Tool
    {
        return AdminTool::make(
            'update_price',
            'Modifica el precio de venta de un producto o servicio en el inventario.'
        )
            ->withStringParameter('item_name', 'Nombre del item a cambiar precio', true)
            ->withNumberParameter('new_price', 'Nuevo precio en USD', true)
            ->requiresApproval(true)
            ->using(function (string $item_name = '', float $new_price = 0.0) {
                $business = BusinessContext::get();
                if (! $business) {
                    return json_encode(['status' => 'error', 'message' => 'No hay negocio activo en contexto.'], JSON_UNESCAPED_UNICODE);
                }

                /** @var InventoryItem|null $item */
                $item = InventoryItem::where('business_id', $business->id)
                    ->where('name', 'like', "%{$item_name}%")
                    ->first();

                if (! $item) {
                    return json_encode(['status' => 'error', 'message' => "No se encontró el item '{$item_name}'."], JSON_UNESCAPED_UNICODE);
                }

                $oldPriceFormatted = $item->price instanceof Money ? $item->price->format() : $item->price;
                $cleanPriceCents = (int) round(max(0.0, (float) $new_price) * 100);
                $newMoney = Money::USD($cleanPriceCents);
                $item->update(['price' => $newMoney]);

                return json_encode([
                    'status' => 'success',
                    'message' => "Precio de '{$item->name}' actualizado de {$oldPriceFormatted} USD a {$newMoney->format()} USD.",
                ], JSON_UNESCAPED_UNICODE);
            })
            ->build($user);
    }
}
