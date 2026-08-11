<?php

namespace App\Services;

use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class InventoryService
{
    /**
     * Adjust stock for an item in an atomic transaction and log it to the immutable ledger.
     */
    public function adjustStock(
        InventoryItem $item,
        int $delta,
        string $type,
        ?string $reason = null,
        ?Model $source = null,
        ?User $user = null
    ): InventoryTransaction {
        return DB::transaction(function () use ($item, $delta, $type, $reason, $source, $user) {
            /** @var InventoryItem $lockedItem */
            $lockedItem = InventoryItem::where('id', $item->id)
                ->lockForUpdate()
                ->firstOrFail();

            $newStock = max(0, $lockedItem->stock + $delta);
            $lockedItem->update(['stock' => $newStock]);

            return InventoryTransaction::create([
                'business_id' => $lockedItem->business_id,
                'inventory_item_id' => $lockedItem->id,
                'type' => $type,
                'quantity' => $delta,
                'balance_after' => $newStock,
                'reason' => $reason,
                'source_type' => $source ? $source->getMorphClass() : null,
                'source_id' => $source?->getKey(),
                'user_id' => $user?->id,
            ]);
        });
    }
}
