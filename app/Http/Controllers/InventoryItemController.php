<?php

namespace App\Http\Controllers;

use App\Models\InventoryItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InventoryItemController extends Controller
{
    /**
     * Display a listing of the inventory items.
     */
    public function index(): Response
    {
        $items = InventoryItem::with('requiredSupplies')->orderBy('created_at', 'desc')->get();
        $lowStockItems = $items->filter(fn ($item) => $item->isLowStock())->values();

        return Inertia::render('Inventory/Index', [
            'items' => $items,
            'lowStockItems' => $lowStockItems,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): Response
    {
        $supplies = InventoryItem::supplies()->get(['id', 'name', 'stock', 'type']);

        return Inertia::render('Inventory/Create', [
            'supplies' => $supplies,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'type' => 'required|string',
            'price' => 'required|numeric|min:0',
            'stock' => 'required|integer|min:0',
            'min_stock' => 'nullable|integer|min:0',
            'attributes' => 'nullable|array',
            'supplies' => 'nullable|array',
            'supplies.*.id' => 'required|exists:inventory_items,id',
            'supplies.*.quantity' => 'required|integer|min:1',
        ]);

        $item = InventoryItem::create([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'type' => $validated['type'],
            'price' => $validated['price'],
            'stock' => $validated['stock'],
            'min_stock' => $validated['min_stock'] ?? 0,
            'attributes' => $validated['attributes'] ?? null,
        ]);

        if (! empty($validated['supplies']) && $validated['type'] === 'service') {
            $syncData = [];
            foreach ($validated['supplies'] as $sup) {
                $syncData[$sup['id']] = ['quantity_required' => $sup['quantity']];
            }
            $item->requiredSupplies()->sync($syncData);
        }

        return redirect()->route('inventory.index')->with('message', 'Item creado exitosamente');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(InventoryItem $inventory): RedirectResponse
    {
        $inventory->delete();

        return redirect()->route('inventory.index')->with('message', 'Item eliminado');
    }
}
