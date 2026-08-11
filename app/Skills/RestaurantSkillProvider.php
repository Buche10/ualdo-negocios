<?php

namespace App\Skills;

use App\Models\Appointment;
use App\Models\Business;
use App\Models\Contact;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Resource;
use App\Services\BusinessContext;
use App\Services\OrderService;
use App\Services\PaymentService;
use Carbon\Carbon;
use Cknow\Money\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Tool;

class RestaurantSkillProvider implements SkillProvider
{
    public function __construct(
        protected OrderService $orderService,
        protected PaymentService $paymentService
    ) {}

    public function vertical(): string
    {
        return 'restaurant';
    }

    public function getTools(?Contact $contact = null): array
    {
        return [
            $this->checkAvailabilityTool($contact),
            $this->reserveTableTool($contact),
            $this->placeOrderTool($contact),
            $this->searchMenuTool(),
            $this->generatePaymentLinkTool($contact),
        ];
    }

    public function promptSection(?Business $business, Contact $contact, string $tz): string
    {
        return <<<'PROMPT'
- Tipo de negocio: Restaurante / Gastronomía.
- Objetivos: Atender amablemente a los clientes por WhatsApp, mostrar el menú, tomar pedidos y agendar reservas de mesas.
- Vocabulario: Usar "cliente", "mesa", "reserva", "platillo/menú" y "pedido".

REGLAS DE ACTUACIÓN DEL VERTICAL RESTAURANTE:
1. Sé atento, amable y antojadizo con las descripciones del menú.
2. Verifica disponibilidad de mesas con `check_availability` antes de confirmar reservas.
3. Si la mesa está libre, usa `reserve_table` para registrar la reservación.
4. Para tomar pedidos de platillos o bebidas, usa `place_order`.
PROMPT;
    }

    protected function checkAvailabilityTool(?Contact $contact = null): Tool
    {
        return (new Tool)
            ->as('check_availability')
            ->for('Consulta la disponibilidad de mesas y horarios en el restaurante.')
            ->withStringParameter('date', 'Fecha a consultar en formato YYYY-MM-DD', true)
            ->withNumberParameter('guests', 'Número de comensales (opcional)', false)
            ->using(function (string $date, ?float $guests = 2) use ($contact) {
                try {
                    /** @var Business|null $business */
                    $business = BusinessContext::get() ?? $contact?->business ?? Business::first();
                    $tz = $business?->timezone ?? config('app.timezone', 'America/Guayaquil');

                    $dayStart = Carbon::parse("{$date} 00:00:00", $tz);
                    $dayEnd = Carbon::parse("{$date} 23:59:59", $tz);

                    $tables = Resource::where('type', 'table')
                        ->where('is_active', true)
                        ->where(function ($q) use ($guests) {
                            if ($guests && $guests > 0) {
                                $q->whereNull('capacity')->orWhere('capacity', '>=', (int) $guests);
                            }
                        })
                        ->get();

                    $bookedApps = Appointment::whereBetween('start_time', [$dayStart, $dayEnd])
                        ->where('status', '!=', 'cancelled')
                        ->get();

                    $availableTables = [];
                    foreach ($tables as $table) {
                        $isBooked = $bookedApps->first(fn ($app) => $app->resource_id === $table->id);
                        if (! $isBooked) {
                            $availableTables[] = [
                                'id' => $table->id,
                                'name' => $table->name,
                                'capacity' => $table->capacity,
                            ];
                        }
                    }

                    return json_encode([
                        'status' => 'success',
                        'date' => $date,
                        'available_tables' => $availableTables,
                    ], JSON_UNESCAPED_UNICODE);
                } catch (\Exception $e) {
                    return json_encode(['status' => 'error', 'message' => 'Error consultando disponibilidad.'], JSON_UNESCAPED_UNICODE);
                }
            });
    }

    protected function reserveTableTool(?Contact $contact = null): Tool
    {
        return (new Tool)
            ->as('reserve_table')
            ->for('Reserva una mesa para el cliente en el restaurante.')
            ->withStringParameter('datetime', 'Fecha y hora de la reserva en formato YYYY-MM-DD HH:MM', true)
            ->withNumberParameter('guests', 'Número de comensales', true)
            ->withStringParameter('table_name', 'Nombre o número de mesa (opcional)', false)
            ->using(function (string $datetime, float $guests, ?string $table_name = null) use ($contact) {
                try {
                    /** @var Business|null $business */
                    $business = BusinessContext::get() ?? $contact?->business ?? Business::first();
                    $tz = $business?->timezone ?? config('app.timezone', 'America/Guayaquil');
                    $startTime = Carbon::parse($datetime, $tz);
                    $endTime = $startTime->copy()->addMinutes(90);

                    $query = Resource::where('type', 'table')->where('is_active', true);
                    if (! empty($table_name)) {
                        $query->where('name', 'like', "%{$table_name}%");
                    }
                    if ($guests > 0) {
                        $query->where(fn ($q) => $q->whereNull('capacity')->orWhere('capacity', '>=', (int) $guests));
                    }

                    $table = $query->first();
                    if (! $table) {
                        return json_encode(['status' => 'error', 'message' => 'No se encontró mesa disponible con la capacidad solicitada.'], JSON_UNESCAPED_UNICODE);
                    }

                    $appointment = DB::transaction(function () use ($startTime, $endTime, $table, $contact, $guests) {
                        $overlap = Appointment::where('resource_id', $table->id)
                            ->where('status', '!=', 'cancelled')
                            ->where('start_time', '<', $endTime)
                            ->where('end_time', '>', $startTime)
                            ->lockForUpdate()
                            ->exists();

                        if ($overlap) {
                            return null;
                        }

                        return Appointment::create([
                            'contact_id' => $contact?->id,
                            'resource_id' => $table->id,
                            'title' => "Reserva de Mesa ({$table->name}) - {$guests} personas",
                            'description' => "Reserva de mesa para {$guests} comensales vía WhatsApp",
                            'start_time' => $startTime,
                            'end_time' => $endTime,
                            'status' => 'scheduled',
                        ]);
                    });

                    if (! $appointment) {
                        return json_encode(['status' => 'error', 'message' => "La mesa '{$table->name}' ya se encuentra ocupada en ese horario."], JSON_UNESCAPED_UNICODE);
                    }

                    return json_encode([
                        'status' => 'success',
                        'message' => "Mesa '{$table->name}' reservada con éxito para el {$startTime->format('d/m/Y H:i')}.",
                        'reservation_id' => $appointment->id,
                    ], JSON_UNESCAPED_UNICODE);
                } catch (\Exception $e) {
                    return json_encode(['status' => 'error', 'message' => 'Error al procesar la reserva de mesa.'], JSON_UNESCAPED_UNICODE);
                }
            });
    }

    protected function placeOrderTool(?Contact $contact = null): Tool
    {
        return (new Tool)
            ->as('place_order')
            ->for('Registra un pedido de comida/platillos para el cliente y descuenta el stock de ingredientes/menú.')
            ->withStringParameter('item_name', 'Nombre del platillo o producto a pedir', true)
            ->withNumberParameter('quantity', 'Cantidad a pedir (por defecto 1)', false)
            ->using(function (string $item_name, ?float $quantity = 1) use ($contact) {
                try {
                    /** @var Business|null $business */
                    $business = BusinessContext::get() ?? $contact?->business ?? Business::first();
                    if (! $business) {
                        return json_encode(['status' => 'error', 'message' => 'No hay negocio activo configurado.'], JSON_UNESCAPED_UNICODE);
                    }
                    $qty = max(1, (int) $quantity);

                    /** @var InventoryItem|null $item */
                    $item = InventoryItem::where('name', 'like', "%{$item_name}%")->first();
                    if (! $item) {
                        return json_encode(['status' => 'error', 'message' => "No se encontró el producto o platillo '{$item_name}' en el menú."], JSON_UNESCAPED_UNICODE);
                    }

                    $unitCents = $item->price instanceof Money ? (int) $item->price->getAmount() : (int) round(((float) $item->price) * 100);
                    $lineTotalCents = $unitCents * $qty;

                    $order = Order::create([
                        'business_id' => $business->id,
                        'contact_id' => $contact?->id,
                        'status' => 'draft',
                        'payment_method' => 'online',
                        'payment_status' => 'pending',
                        'subtotal_cents' => $lineTotalCents,
                        'total_cents' => $lineTotalCents,
                    ]);

                    OrderItem::create([
                        'order_id' => $order->id,
                        'inventory_item_id' => $item->id,
                        'name' => $item->name,
                        'unit_price_cents' => $unitCents,
                        'quantity' => $qty,
                        'line_total_cents' => $lineTotalCents,
                    ]);

                    $confirmedOrder = $this->orderService->confirm($order);
                    $formattedTotal = number_format($lineTotalCents / 100, 2);

                    return json_encode([
                        'status' => 'success',
                        'message' => "Pedido #{$confirmedOrder->id} confirmado por {$qty}x '{$item->name}' (\${$formattedTotal} USD).",
                        'order_id' => $confirmedOrder->id,
                        'total' => "\${$formattedTotal}",
                    ], JSON_UNESCAPED_UNICODE);
                } catch (\Exception $e) {
                    Log::error('Error en place_order: '.$e->getMessage());

                    return json_encode(['status' => 'error', 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
                }
            });
    }

    protected function searchMenuTool(): Tool
    {
        return (new Tool)
            ->as('search_services')
            ->for('Busca platillos, bebidas o menús en la carta del restaurante.')
            ->withStringParameter('query', 'Platillo o concepto a buscar', true)
            ->using(function (string $query) {
                $items = InventoryItem::where('name', 'like', "%{$query}%")
                    ->orWhere('description', 'like', "%{$query}%")
                    ->get();

                return json_encode([
                    'status' => 'success',
                    'items' => $items->map(fn ($i) => $i->toLlmArray())->values()->toArray(),
                ], JSON_UNESCAPED_UNICODE);
            });
    }

    protected function generatePaymentLinkTool(?Contact $contact = null): Tool
    {
        return (new Tool)
            ->as('generate_payment_link')
            ->for('Genera enlace de pago online o instrucción de pago en local para el cliente.')
            ->withNumberParameter('amount', 'Monto en USD', false)
            ->withStringParameter('description', 'Concepto', false)
            ->withStringParameter('method', 'online o presencial', false)
            ->using(function (?float $amount = 10.0, string $description = 'Consumo Restaurante', ?string $method = 'online') use ($contact) {
                $selectedMethod = in_array($method, ['online', 'presencial'], true) ? $method : 'online';
                $res = $this->paymentService->generatePaymentLink($contact, $amount ?? 10.0, $description, $selectedMethod);

                return json_encode($res, JSON_UNESCAPED_UNICODE);
            });
    }
}
