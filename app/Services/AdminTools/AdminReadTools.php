<?php

namespace App\Services\AdminTools;

use App\Models\Appointment;
use App\Models\Contact;
use App\Models\Doctor;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\Order;
use App\Models\User;
use App\Services\BusinessContext;
use Carbon\Carbon;
use Prism\Prism\Tool;

class AdminReadTools
{
    /**
     * Get array of Prism tools for read-only admin operations.
     *
     * @return array<int, Tool>
     */
    public function getTools(?User $user = null): array
    {
        return [
            $this->whatToBuyTool($user),
            $this->searchInventoryTool($user),
            $this->todaysAgendaTool($user),
            $this->getOrdersTool($user),
            $this->getInventoryLedgerTool($user),
        ];
    }

    /**
     * Tool: what_to_buy
     * Uses a single SQL query to fetch items where stock <= min_stock.
     */
    public function whatToBuyTool(?User $user = null): Tool
    {
        return AdminTool::make(
            'what_to_buy',
            'Consulta los insumos y productos del inventario cuyo stock actual es menor o igual al mínimo requerido (bajas existencias/faltantes).'
        )
            ->using(function () {
                $business = BusinessContext::get();
                if (! $business) {
                    return json_encode(['status' => 'error', 'message' => 'No hay negocio activo en contexto.'], JSON_UNESCAPED_UNICODE);
                }

                // Single SQL query — no filtering in PHP
                $lowStockItems = InventoryItem::where('business_id', $business->id)
                    ->whereColumn('stock', '<=', 'min_stock')
                    ->orderBy('stock', 'asc')
                    ->get();

                return json_encode([
                    'status' => 'success',
                    'count' => $lowStockItems->count(),
                    'items' => $lowStockItems->map(fn (InventoryItem $item) => $item->toLlmArray())->values()->toArray(),
                ], JSON_UNESCAPED_UNICODE);
            })
            ->build($user);
    }

    /**
     * Tool: search_inventory
     * Searches inventory using indexed database query.
     */
    public function searchInventoryTool(?User $user = null): Tool
    {
        return AdminTool::make(
            'search_inventory',
            'Busca insumos, productos o servicios en el catálogo del inventario por nombre o descripción.'
        )
            ->withStringParameter('query', 'Término de búsqueda o nombre del producto/insumo', true)
            ->using(function (string $query = '') {
                $business = BusinessContext::get();
                if (! $business) {
                    return json_encode(['status' => 'error', 'message' => 'No hay negocio activo en contexto.'], JSON_UNESCAPED_UNICODE);
                }

                $items = InventoryItem::where('business_id', $business->id)
                    ->where(function ($q) use ($query) {
                        $q->where('name', 'like', "%{$query}%")
                            ->orWhere('description', 'like', "%{$query}%");
                    })
                    ->take(20)
                    ->get();

                return json_encode([
                    'status' => 'success',
                    'count' => $items->count(),
                    'items' => $items->map(fn (InventoryItem $item) => $item->toLlmArray())->values()->toArray(),
                ], JSON_UNESCAPED_UNICODE);
            })
            ->build($user);
    }

    /**
     * Tool: todays_agenda
     * Queries appointments for current date in business timezone using indexed queries.
     */
    public function todaysAgendaTool(?User $user = null): Tool
    {
        return AdminTool::make(
            'todays_agenda',
            'Consulta la agenda de citas programadas para el día de hoy en el consultorio/negocio.'
        )
            ->using(function () {
                $business = BusinessContext::get();
                if (! $business) {
                    return json_encode(['status' => 'error', 'message' => 'No hay negocio activo en contexto.'], JSON_UNESCAPED_UNICODE);
                }

                $tz = $business->timezone ?? config('app.timezone', 'America/Guayaquil');
                $todayStart = Carbon::now($tz)->startOfDay();
                $todayEnd = Carbon::now($tz)->endOfDay();

                $appointments = Appointment::with(['contact', 'doctor'])
                    ->where('business_id', $business->id)
                    ->whereBetween('start_time', [$todayStart, $todayEnd])
                    ->orderBy('start_time', 'asc')
                    ->get()
                    ->map(function (Appointment $app) use ($tz) {
                        /** @var Contact|null $contact */
                        $contact = $app->contact;
                        /** @var Doctor|null $doctor */
                        $doctor = $app->doctor;

                        return [
                            'id' => $app->id,
                            'title' => $app->title,
                            'start_time' => Carbon::parse($app->start_time)->timezone($tz)->format('H:i'),
                            'end_time' => Carbon::parse($app->end_time)->timezone($tz)->format('H:i'),
                            'status' => $app->status,
                            'patient_name' => $contact ? $contact->name : 'Cliente',
                            'doctor_name' => $doctor ? $doctor->name : 'No asignado',
                        ];
                    });

                return json_encode([
                    'status' => 'success',
                    'date' => Carbon::now($tz)->format('Y-m-d'),
                    'timezone' => $tz,
                    'total_appointments' => $appointments->count(),
                    'appointments' => $appointments->toArray(),
                ], JSON_UNESCAPED_UNICODE);
            })
            ->build($user);
    }

    /**
     * Tool: get_orders
     * Consults recent orders placed in the business.
     */
    public function getOrdersTool(?User $user = null): Tool
    {
        return AdminTool::make(
            'get_orders',
            'Consulta los pedidos y ventas recientes registrados en el negocio.'
        )
            ->withStringParameter('status', 'Filtrar por estado: draft, confirmed, paid, fulfilled, cancelled (opcional)', false)
            ->using(function (?string $status = null) {
                $business = BusinessContext::get();
                if (! $business) {
                    return json_encode(['status' => 'error', 'message' => 'No hay negocio activo en contexto.'], JSON_UNESCAPED_UNICODE);
                }

                $query = Order::with(['contact', 'items'])
                    ->where('business_id', $business->id);

                if (! empty($status)) {
                    $query->where('status', $status);
                }

                $orders = $query->orderBy('created_at', 'desc')->take(15)->get()
                    ->map(function (Order $o) {
                        /** @var Contact|null $contact */
                        $contact = $o->contact;

                        return [
                            'id' => $o->id,
                            'status' => $o->status,
                            'payment_status' => $o->payment_status,
                            'total' => '$'.number_format($o->total_cents / 100, 2),
                            'customer' => $contact ? $contact->name : 'Cliente Walk-in',
                            'placed_at' => $o->placed_at?->toDateTimeString() ?? $o->created_at->toDateTimeString(),
                            'items_count' => $o->items->count(),
                        ];
                    });

                return json_encode([
                    'status' => 'success',
                    'count' => $orders->count(),
                    'orders' => $orders->toArray(),
                ], JSON_UNESCAPED_UNICODE);
            })
            ->build($user);
    }

    /**
     * Tool: get_inventory_ledger
     * Consults the immutable inventory transactions ledger (Kardex).
     */
    public function getInventoryLedgerTool(?User $user = null): Tool
    {
        return AdminTool::make(
            'get_inventory_ledger',
            'Consulta el historial de movimientos de inventario (Kardex / Ledger de stock) del negocio.'
        )
            ->withStringParameter('item_name', 'Filtrar por nombre de producto o insumo (opcional)', false)
            ->using(function (?string $item_name = null) {
                $business = BusinessContext::get();
                if (! $business) {
                    return json_encode(['status' => 'error', 'message' => 'No hay negocio activo en contexto.'], JSON_UNESCAPED_UNICODE);
                }

                $query = InventoryTransaction::with('item')
                    ->where('business_id', $business->id);

                if (! empty($item_name)) {
                    $query->whereHas('item', fn ($q) => $q->where('name', 'like', "%{$item_name}%"));
                }

                $transactions = $query->orderBy('created_at', 'desc')->take(20)->get()
                    ->map(function (InventoryTransaction $tx) {
                        /** @var InventoryItem|null $item */
                        $item = $tx->item;

                        return [
                            'id' => $tx->id,
                            'item_name' => $item ? $item->name : 'Desconocido',
                            'type' => $tx->type,
                            'quantity' => $tx->quantity,
                            'balance_after' => $tx->balance_after,
                            'reason' => $tx->reason,
                            'created_at' => $tx->created_at->toDateTimeString(),
                        ];
                    });

                return json_encode([
                    'status' => 'success',
                    'count' => $transactions->count(),
                    'transactions' => $transactions->toArray(),
                ], JSON_UNESCAPED_UNICODE);
            })
            ->build($user);
    }
}
