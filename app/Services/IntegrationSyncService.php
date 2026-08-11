<?php

namespace App\Services;

use App\Integrations\DoblefiloConnector;
use App\Integrations\Dto\CanonicalItem;
use App\Jobs\RetryDoblefiloPushJob;
use App\Models\BusinessIntegration;
use App\Models\IntegrationMapping;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\SyncRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * IntegrationSyncService — Motor de sincronización de inventario canónico.
 *
 * NOTA DE ARQUITECTURA (SEMÁNTICA DE ESPEJO):
 * Doble Filo es la fuente de verdad canónica para catálogo e inventario de insumos (raw_material).
 * Los ítems importados son de solo-lectura del lado Ualdo. Cada sincronización reconcilia
 * el stock local de Ualdo igualándolo al origen en Doble Filo (delta = item.stock - currentStock)
 * y registrando el ajuste en el Kardex (inventory_transactions).
 */
class IntegrationSyncService
{
    public function __construct(
        protected InventoryService $inventoryService
    ) {}

    public function pull(BusinessIntegration $integration): SyncRun
    {
        $startTime = microtime(true);

        /** @var SyncRun $syncRun */
        $syncRun = SyncRun::create([
            'business_id' => $integration->business_id,
            'provider' => $integration->provider,
            'direction' => 'pull',
            'status' => 'running',
            'started_at' => now(),
        ]);

        try {
            // Pre-sync reconciliation: Flush any failed mirror pushes before pulling stock
            $failedTxs = InventoryTransaction::where('business_id', $integration->business_id)
                ->where('mirror_status', 'failed')
                ->get();

            foreach ($failedTxs as $failedTx) {
                try {
                    (new RetryDoblefiloPushJob($failedTx))->handle();
                } catch (Throwable $e) {
                    Log::warning("Pre-sync push retry failed for transaction #{$failedTx->id}: ".$e->getMessage());
                }
            }

            $connector = new DoblefiloConnector($integration);
            /** @var array<int, CanonicalItem> $items */
            $items = $connector->pull();
        } catch (Throwable $e) {
            $syncRun->update([
                'status' => 'failed',
                'errors' => ['global' => $e->getMessage()],
                'finished_at' => now(),
                'duration_ms' => (int) round((microtime(true) - $startTime) * 1000),
            ]);

            throw $e;
        }

        $pulled = count($items);
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];

        foreach ($items as $item) {
            try {
                $result = $this->syncItem($integration, $item);
                if ($result === 'created') {
                    $created++;
                } elseif ($result === 'updated') {
                    $updated++;
                } else {
                    $skipped++;
                }
            } catch (Throwable $e) {
                $errors[] = [
                    'external_id' => $item->externalId,
                    'name' => $item->name,
                    'error' => $e->getMessage(),
                ];
            }
        }

        $status = empty($errors) ? 'success' : ($created > 0 || $updated > 0 || $skipped > 0 ? 'partial' : 'failed');
        $durationMs = (int) round((microtime(true) - $startTime) * 1000);

        $syncRun->update([
            'status' => $status,
            'pulled' => $pulled,
            'created_count' => $created,
            'updated_count' => $updated,
            'skipped' => $skipped,
            'errors' => empty($errors) ? null : $errors,
            'finished_at' => now(),
            'duration_ms' => $durationMs,
        ]);

        $integration->update([
            'last_synced_at' => now(),
        ]);

        return $syncRun;
    }

    protected function syncItem(BusinessIntegration $integration, CanonicalItem $item): string
    {
        return DB::transaction(function () use ($integration, $item) {
            /** @var IntegrationMapping|null $mapping */
            $mapping = IntegrationMapping::where('provider', $integration->provider)
                ->where('external_id', $item->externalId)
                ->first();

            $itemHash = $item->hash();

            if ($mapping && $mapping->external_hash === $itemHash) {
                return 'skipped';
            }

            $attributes = array_merge([
                'sku' => $item->externalId,
                'unit' => $item->unit,
                'category' => $item->category,
                'external_source' => $integration->provider,
                'available_exact' => $item->stockExact,
                'is_active' => $item->isActive,
            ], $item->meta);

            if (! $mapping) {
                // Initial creation
                $invItem = InventoryItem::create([
                    'business_id' => $integration->business_id,
                    'name' => $item->name,
                    'type' => 'supply',
                    'price' => 0,
                    'stock' => 0,
                    'min_stock' => $item->minStock,
                    'attributes' => $attributes,
                ]);

                if ($item->stock != 0) {
                    $this->inventoryService->adjustStock(
                        item: $invItem,
                        delta: $item->stock,
                        type: 'adjust',
                        reason: "Initial sync from {$integration->provider} (#{$item->externalId})",
                        source: $integration
                    );
                }

                IntegrationMapping::create([
                    'business_id' => $integration->business_id,
                    'provider' => $integration->provider,
                    'external_id' => $item->externalId,
                    'external_uid' => $item->externalUid,
                    'inventory_item_id' => $invItem->id,
                    'external_hash' => $itemHash,
                ]);

                return 'created';
            }

            // Update existing mapping
            /** @var InventoryItem|null $invItem */
            $invItem = $mapping->inventoryItem;
            if (! $invItem) {
                $invItem = InventoryItem::create([
                    'business_id' => $integration->business_id,
                    'name' => $item->name,
                    'type' => 'supply',
                    'price' => 0,
                    'stock' => 0,
                    'min_stock' => $item->minStock,
                    'attributes' => $attributes,
                ]);
                $mapping->update(['inventory_item_id' => $invItem->id, 'external_uid' => $item->externalUid]);
            } else {
                $existingAttributes = $invItem->attributes ?? [];
                $invItem->update([
                    'name' => $item->name,
                    'min_stock' => $item->minStock,
                    'attributes' => array_merge($existingAttributes, $attributes),
                ]);
            }

            $currentStock = $invItem->stock;
            $stockDelta = $item->stock - $currentStock;

            // Protect local stock if item has an un-synced push waiting for retry
            $hasUnsyncedPush = InventoryTransaction::where('inventory_item_id', $invItem->id)
                ->where('mirror_status', 'failed')
                ->exists();

            if ($stockDelta != 0 && ! $hasUnsyncedPush) {
                $this->inventoryService->adjustStock(
                    item: $invItem,
                    delta: $stockDelta,
                    type: 'adjust',
                    reason: "Sync adjustment from {$integration->provider} (#{$item->externalId})",
                    source: $integration
                );
            }

            $mapping->update([
                'external_uid' => $item->externalUid,
                'external_hash' => $itemHash,
            ]);

            return 'updated';
        });
    }
}
