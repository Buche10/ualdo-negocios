# Plan: Plataforma Multi-Vertical + Inventario Operativo

> Estado objetivo: que Ualdo administre **cualquier tipo de negocio** (consultorio, restaurante, retail, barbería…)
> a través del bot de cliente (WhatsApp/Telegram) y del co-piloto Admin del dashboard,
> con un **inventario operativo real** (no solo catálogo).
>
> Principio rector: **no reescribir lo que ya existe**. La verticalización y el inventario ya están
> parcialmente construidos; este plan los **conecta y profundiza**, sin duplicar.

---

## Diagnóstico de partida (lo que ya existe)

| Capacidad | Estado actual |
|-----------|---------------|
| Co-piloto Admin ("Ualdo Admin") | ✅ Funcional, agnóstico al vertical. Prompt 3 capas + `ApprovalGate`. |
| Inventario CRUD + IA | ✅ 4 tools (`add_inventory_item`, `update_stock`, `delete`, `update_price`), alertas stock bajo. |
| Staff / secretaria (Telegram) | ✅ Tareas, ruteo por rol, memorias, handoff humano. |
| Multi-tenant | ✅ `BusinessScope` fail-closed. Aislamiento correcto. |
| Campo `business.vertical` | ⚠️ Se guarda en onboarding pero **NO cambia nada**. |
| Bot de cliente | ⚠️ Siempre carga `HealthSkillsService` a fuego fijo. Prompt hardcodeado a "consultorio". |
| `InventoryTransaction` | ⚠️ Modelo **huérfano** (0 referencias). Sin libro de movimientos. |
| Ventas/Pedidos (`Order`) | ❌ No existe. Stock solo se descuenta al agendar cita. |

**Los 3 huecos reales:**
1. La verticalización está desconectada (el keystone).
2. El inventario es catálogo, no operación (sin ledger, sin reposición, sin reportes).
3. No hay concepto de venta/pedido fuera de citas.

---

## Arquitectura objetivo

### Pieza central: `SkillProvider` + `SkillRegistry`

Un contrato que cada vertical implementa, y un registro que resuelve el correcto según `business.vertical`.

```
interface SkillProvider
    vertical(): string
    getTools(?Contact): array           // herramientas Prism del bot de cliente
    getSystemPromptSection(Business, Contact): string   // identidad + reglas del vertical

SkillRegistry::for($business->vertical): SkillProvider
    'health'     => HealthSkillsService       (refactor del actual)
    'restaurant' => RestaurantSkillsService    (nuevo)
    'retail'     => RetailSkillsService         (nuevo)
    default      => GenericSkillsService        (fallback seguro)
```

`UaldoManagerService` deja de instanciar `HealthSkillsService` directo y pide al registro
las tools + la sección de prompt según el vertical del negocio en contexto.

### Prompt del bot de cliente → 3 capas (igual que el Admin)

Hoy el bot de cliente tiene un prompt plano. Se adopta el patrón de 3 capas ya probado en `UaldoAdminService`:
- **Capa 1 — Base común**: identidad Ualdo, contexto de tiempo/negocio, reglas de tono para WhatsApp.
- **Capa 2 — Persona del negocio**: `business.settings['ai_persona']` (ya existe el mecanismo).
- **Capa 3 — Vertical**: la aporta el `SkillProvider` (vocabulario y reglas del dominio).

### Inventario como fuente de verdad única

Todo cambio de stock pasa por un único `InventoryService::adjustStock(item, qty, type, reason, source)`
que **escribe siempre** una fila en `InventoryTransaction` (ledger inmutable).
Hoy hay mutaciones dispersas (agendar cita, tools admin) que escriben `stock` directo; se centralizan.

### Modelo de dominio: 2 ejes, no forzar uno

- **Eje temporal (reserva de recurso en un slot)**: hoy es `Appointment` (atado a `doctor_id`).
  Se generaliza a un **recurso asignable** (doctor / mesa / silla / estación) vía un modelo `Resource`
  liviano, para que "reservar mesa" reutilice la misma lógica anti-solape que ya existe.
- **Eje transaccional (venta que descuenta stock)**: nuevo `Order` + `OrderItem`.
  Un item vendido puede tener `requiredSupplies` (el pivot `service_supplies` **ya existe**),
  así una venta de un platillo descuenta sus insumos = recetas de restaurante gratis.

---

## Fases

### Fase 0 — Fundaciones del registro (keystone, sin cambio visible)
**Objetivo:** desacoplar el bot del vertical "salud" sin cambiar comportamiento.
- Definir `SkillProvider` (interface) y `SkillRegistry`.
- Refactor `HealthSkillsService` para implementar `SkillProvider` (mover el prompt de cliente hardcodeado adentro).
- `UaldoManagerService` resuelve el provider por `business->vertical` (con `health` como única impl → salida idéntica).
- Adoptar prompt 3 capas en el bot de cliente.
- **Riesgo:** bajo. Cubierto por `HealthAiAgentTest` existente (debe seguir verde sin tocar el test).
- **Salida:** misma funcionalidad, arquitectura lista para enchufar verticales.

### Fase 1 — Inventario operativo (agnóstico, beneficia a todos)
- Activar `InventoryTransaction` como **ledger inmutable** (`type`: in/out/adjust/waste; qty; reason; source; ref; user).
- Centralizar TODA mutación de stock en `InventoryService::adjustStock()` (agendar cita, tools admin, futuras ventas).
- Añadir **reposición** (restock/entrada) + campo `supplier` opcional.
- Reportes básicos: valor de inventario, consumo por periodo, kardex por item (ya hay low-stock).
- **Salida:** trazabilidad completa "quién movió qué y cuándo".

### Fase 2 — Ventas/Pedidos (habilita verticales transaccionales)
- Modelos `Order` + `OrderItem` (business-scoped, con `BelongsToBusiness`).
- Flujo de estado (borrador → confirmado → pagado/entregado/cancelado).
- Al confirmar: descuenta stock vía `InventoryService` (ledger) + link de pago opcional.
- Reutiliza `requiredSupplies` para descontar insumos de cada producto vendido.
- **Dependencia:** Fase 1 (el ledger).

### Fase 3 — Primer vertical nuevo: Restaurante (prueba del diseño)
- `RestaurantSkillsService implements SkillProvider` con tools:
  `ver_menu` (reutiliza search_services), `tomar_pedido` (Order), `reservar_mesa` (Resource+slot), `estado_pedido`.
- Introducir `Resource` liviano para generalizar el anti-solape de citas → reservas de mesa.
- **Salida:** un restaurante onboarded recibe un bot que habla de menú y pedidos, no de citas médicas.
  Retail queda casi trivial después de esto.

### Fase 4 — Onboarding + seeding por vertical + Admin agnóstico
- Onboarding siembra catálogo/config/persona por defecto según el vertical elegido.
- Verificar que las tools del Admin son genéricas; añadir tools de lectura de pedidos y reportes.
- Ajustar el dashboard para mostrar módulos según vertical (citas vs pedidos).

---

## Decisiones abiertas (requieren tu input antes de construir)

1. **`Resource` genérico vs mantener `doctor_id`**: introducir el modelo `Resource` en Fase 3
   es más limpio pero toca `Appointment`. Alternativa mínima: dejar citas como están y modelar
   mesas como recurso aparte. → Recomendado: `Resource` genérico, migración con `doctor_id` como caso particular.
2. **Pago real**: hoy el link de pago es simulado. Ventas y reservas con abono lo necesitan de verdad.
   ¿Entra una pasarela (PayPhone/Stripe) en Fase 2 o se sigue simulando?
3. **Alcance de verticales v1**: ¿solo restaurante como segundo vertical, o restaurante + retail juntos?

---

## Orden recomendado
**Fase 0 → Fase 1 → Fase 2 → Fase 3 → Fase 4.**
La Fase 0 es barata y desbloquea todo; la Fase 1 aporta valor a los consultorios actuales de inmediato
(sin esperar a los nuevos verticales); las Fases 2–3 abren restaurante/retail.
