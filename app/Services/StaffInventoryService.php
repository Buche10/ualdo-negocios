<?php

namespace App\Services;

use App\Integrations\DoblefiloConnector;
use App\Integrations\Dto\CanonicalMovement;
use App\Jobs\RetryDoblefiloPushJob;
use App\Models\BusinessIntegration;
use App\Models\IntegrationMapping;
use App\Models\InventoryItem;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

class StaffInventoryService
{
    public function __construct(
        protected InventoryService $inventoryService
    ) {}

    /**
     * Resolve product by fuzzy search (name or SKU) in current business scope.
     */
    public function resolveProduct(string $query): ?InventoryItem
    {
        $cleanQuery = trim($query);
        if (empty($cleanQuery)) {
            return null;
        }

        /** @var InventoryItem|null $item */
        $item = InventoryItem::where(function ($q) use ($cleanQuery) {
            $q->where('name', 'like', "%{$cleanQuery}%")
                ->orWhere('attributes->sku', 'like', "%{$cleanQuery}%");
        })->first();

        return $item;
    }

    /**
     * Register an inventory movement: updates local ledger (source) and mirrors to Doble Filo (espejo).
     *
     * @return array<string, mixed>
     */
    public function register(
        User $staff,
        InventoryItem $item,
        float|int $quantity,
        string $kind,
        ?string $location = null
    ): array {
        $currentStock = $item->stock;
        $unit = $item->attributes['unit'] ?? 'unit';
        $location = $location ?: config('services.doblefilo.default_location', 'kitchen');

        $delta = 0;
        $movementType = 'input';

        if (in_array($kind, ['in', 'input', 'entry'], true)) {
            $delta = (int) round($quantity);
            $movementType = 'input';
        } elseif (in_array($kind, ['out', 'output', 'consumption'], true)) {
            $delta = -(int) round($quantity);
            $movementType = 'output';
        } elseif (in_array($kind, ['count', 'adjustment'], true)) {
            $targetStock = (int) round($quantity);
            $delta = $targetStock - $currentStock;
            $movementType = $delta >= 0 ? 'adjustment_in' : 'adjustment_out';
        }

        // 1. Local Ledger Adjustment (Fuentes Ualdo)
        $reason = "Registro vía Telegram Staff ({$staff->name}): {$kind} {$quantity} {$unit}";
        $tx = $this->inventoryService->adjustStock(
            item: $item,
            delta: $delta,
            type: 'adjust',
            reason: $reason,
            source: $staff
        );

        $newStock = $item->fresh()->stock;

        // 2. Doble Filo Mirror Push (Espejo)
        $mirrorStatus = 'skipped';
        $mirrorPayload = null;

        /** @var BusinessIntegration|null $integration */
        $integration = BusinessIntegration::where('provider', 'doblefilo')
            ->where('enabled', true)
            ->first();

        if ($integration) {
            /** @var IntegrationMapping|null $mapping */
            $mapping = IntegrationMapping::where('provider', 'doblefilo')
                ->where('inventory_item_id', $item->id)
                ->first();

            if ($mapping && ! empty($mapping->external_uid)) {
                $mirrorPayload = [
                    'type' => $movementType,
                    'quantity' => abs($delta),
                    'unit' => $unit,
                    'location' => $location,
                    'notes' => "Registrado por {$staff->name} vía Telegram Ualdo",
                ];

                try {
                    $connector = new DoblefiloConnector($integration);

                    $movement = new CanonicalMovement(
                        productExternalUid: $mapping->external_uid,
                        type: $movementType,
                        quantity: abs($delta),
                        unitSnapshot: $unit,
                        location: $location,
                        notes: "Registrado por {$staff->name} vía Telegram Ualdo",
                        referenceId: $tx->id
                    );

                    $connector->pushMovement($movement);
                    $mirrorStatus = 'synced';
                } catch (Throwable $e) {
                    Log::warning("Mirror push to Doble Filo failed for item #{$item->id}: ".$e->getMessage(), [
                        'exception' => $e,
                    ]);
                    $mirrorStatus = 'failed';

                    RetryDoblefiloPushJob::dispatch($tx);
                }
            }
        }

        $tx->update([
            'mirror_status' => $mirrorStatus,
            'mirror_payload' => $mirrorPayload,
        ]);

        return [
            'status' => 'success',
            'item_id' => $item->id,
            'item_name' => $item->name,
            'kind' => $kind,
            'quantity' => $quantity,
            'delta' => $delta,
            'new_stock' => $newStock,
            'unit' => $unit,
            'location' => $location,
            'mirror_status' => $mirrorStatus,
            'message' => "Registrado en Ualdo: {$item->name} ({$delta} {$unit}). Stock actual: {$newStock} {$unit} [Espejo Doble Filo: {$mirrorStatus}].",
        ];
    }
}
