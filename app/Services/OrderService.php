<?php

namespace App\Services;

use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class OrderService
{
    public function __construct(
        protected InventoryService $inventoryService
    ) {}

    /**
     * Confirm an order and deduct all relevant inventory items / service supplies in a single DB transaction.
     */
    public function confirm(Order $order): Order
    {
        if (in_array($order->status, ['confirmed', 'paid'], true)) {
            return $order;
        }

        return DB::transaction(function () use ($order) {
            $order->loadMissing(['items.inventoryItem.requiredSupplies']);

            foreach ($order->items as $item) {
                /** @var OrderItem $item */
                /** @var InventoryItem|null $inv */
                $inv = $item->inventoryItem;
                if (! $inv) {
                    continue;
                }

                if ($inv->type !== 'service') {
                    // Producto o insumo directo
                    if ($inv->stock < $item->quantity) {
                        throw new RuntimeException("Stock insuficiente para el producto '{$inv->name}' al confirmar pedido #{$order->id}.");
                    }

                    $this->inventoryService->adjustStock(
                        item: $inv,
                        delta: -$item->quantity,
                        type: 'sale',
                        reason: "Venta por pedido #{$order->id}",
                        source: $order
                    );
                } else {
                    // Servicio con receta / insumos requeridos
                    foreach ($inv->requiredSupplies as $supply) {
                        $pivot = $supply->getAttribute('pivot');
                        $requiredQty = ($pivot ? (int) $pivot->quantity_required : 1) * $item->quantity;

                        if ($supply->stock < $requiredQty) {
                            throw new RuntimeException("Stock insuficiente para el insumo '{$supply->name}' al procesar el servicio '{$inv->name}' en pedido #{$order->id}.");
                        }

                        $this->inventoryService->adjustStock(
                            item: $supply,
                            delta: -$requiredQty,
                            type: 'sale',
                            reason: "Consumo de insumo por servicio '{$inv->name}' en pedido #{$order->id}",
                            source: $order
                        );
                    }
                }
            }

            $order->update([
                'status' => 'confirmed',
                'placed_at' => now(),
            ]);

            return $order;
        });
    }
}
