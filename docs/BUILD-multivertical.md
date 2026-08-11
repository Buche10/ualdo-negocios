# Guía de construcción — Plataforma Multi-Vertical genérica

> Plan paso a paso para implementarlo tú mismo. Decisiones ya tomadas:
> **(1)** Modelo genérico (`Resource` en vez de `Doctor`). **(2)** Pasarela real en Fase 2, con opción de **pago presencial**. **(3)** Base **genérica** para cualquier vertical; salud pasa a ser una especialización.
>
> Flujo por fase: **tests primero → implementación → `vendor/bin/pint` + `phpstan` + `pest` verde** antes de pasar a la siguiente.

---

## 0. Convenciones del repo (respétalas en todo lo nuevo)

- **Modelo de tenant nuevo** → usa el trait `App\Traits\BelongsToBusiness`. Añade `BusinessScope` (fail-closed) y auto-rellena `business_id` en `creating`. Nunca filtres `business_id` a mano si el modelo ya lo tiene.
- **Dinero** → SIEMPRE entero en centavos. `Money::USD($cents)`. En modelos usa accessor/mutator como `InventoryItem::price`.
- **Tools del bot de cliente** → `(new Prism\Prism\Tool)->as()->for()->withStringParameter()->using(fn…)`. Devuelven `json_encode([...], JSON_UNESCAPED_UNICODE)`.
- **Tools del Admin** → `AdminTool::make(name, desc)->withRoles([...])->requiresApproval(bool)->using(fn…)->build($user)`. Ya trae wrappers de rol, `ApprovalGate` y `AuditService`.
- **Acciones de alto impacto** → `->requiresApproval(true)`; el flujo de confirmación por token ya existe (`ApprovalGate` + `PendingAction`).
- **Multi-tenant en tests** → crea `Business`, envuelve en `BusinessContext::runInContext($business, fn…)` o `BusinessContext::set($business)`.
- **Anti-solape** → constraint Postgres `EXCLUDE USING gist` + `lockForUpdate` a nivel app. SQLite no lo soporta (solo el guard de app).

---

## 1. Modelo de dominio objetivo

Dos ejes independientes; **no** forzamos un solo modelo:

```
EJE TEMPORAL (reserva de un recurso en un slot)
   appointments  ──FK──▶ resources   (antes doctor_id → resource_id)
   Resource.type ∈ { doctor, table, chair, station, room, staff }

EJE TRANSACCIONAL (venta que descuenta stock)
   orders ──1:N──▶ order_items ──FK──▶ inventory_items
   Order confirma → InventoryService descuenta stock → InventoryTransaction (ledger)

INVENTARIO (fuente de verdad única)
   inventory_items (catálogo, ya existe)
   inventory_transactions (ledger inmutable, HOY huérfano → se activa)
   service_supplies (recetas/BOM, pivot ya existe)

PAGOS
   payments ──FK──▶ orders / appointments   (online | presencial)
```

Decisión pragmática clave: **NO renombramos `appointments` a `reservations`**. Renombrar rompería `HealthSkillsService`, `GoogleCalendarService`, recordatorios y tests. Generalizamos solo la FK (`doctor_id` → `resource_id`) y mantenemos el nombre de tabla. Si más adelante quieres el alias `Reservation`, se añade como modelo apuntando a la misma tabla.

---

## FASE 0 — Registro de skills (keystone, sin cambio de comportamiento)

**Meta:** desacoplar el bot del vertical "salud". Salida idéntica para consultorios (test de regresión).

### Archivos nuevos
- `app/Skills/SkillProvider.php` (interface)
  ```php
  interface SkillProvider {
      public function vertical(): string;                 // 'health', 'restaurant', 'generic'…
      public function getTools(?Contact $contact): array;  // tools Prism del bot de cliente
      public function promptSection(Business $b, Contact $c, string $tz): string; // Capa 3 (vocabulario del dominio)
  }
  ```
- `app/Skills/SkillRegistry.php`
  ```php
  class SkillRegistry {
      public function for(?string $vertical): SkillProvider {
          return match ($vertical) {
              'health'     => app(HealthSkillProvider::class),
              'restaurant' => app(RestaurantSkillProvider::class), // Fase 3
              'retail'     => app(RetailSkillProvider::class),      // Fase 3
              default      => app(GenericSkillProvider::class),
          };
      }
  }
  ```
- `app/Skills/GenericSkillProvider.php` — el **workhorse**: tools que sirven a cualquier negocio
  (`search_services`, `check_availability`, `reserve_slot`, `place_order` [Fase 2], `generate_payment_link`, `transfer_to_human`).
- `app/Skills/HealthSkillProvider.php` — envuelve/hereda el genérico y sobreescribe `promptSection()` con vocabulario médico (paciente/doctor/cita).

### Archivos a editar
- `app/Services/HealthSkillsService.php` → conviértelo en `HealthSkillProvider implements SkillProvider` (o deja el service y crea el provider que delega en él). Mueve el prompt de cliente aquí.
- `app/Services/UaldoManagerService.php`:
  - `buildSystemPrompt()` → adopta **3 capas** (base común + `settings['ai_persona']` + `provider->promptSection()`), igual que `UaldoAdminService::build3LayerSystemPrompt()`.
  - `generateAiResponse()` → `$provider = app(SkillRegistry::class)->for($business?->vertical); $tools = $provider->getTools($contact);`
- `bootstrap/app.php` o un `AppServiceProvider` → bindear `SkillRegistry` singleton si hace falta.

### Tests (primero)
- `tests/Feature/SkillRegistryTest.php`: `for('health')` → HealthSkillProvider; `for('restaurant')` → RestaurantSkillProvider; `for(null)`/desconocido → GenericSkillProvider.
- **Regresión:** `HealthAiAgentTest` **debe seguir verde SIN tocarlo**. Es tu red de seguridad de que la Fase 0 no cambió comportamiento.

### Done
`pest` verde (incl. HealthAiAgentTest intacto), phpstan/pint limpios. El bot se comporta igual, pero ya resuelve tools por vertical.

---

## FASE 1 — Inventario operativo (agnóstico; da valor YA a consultorios)

**Meta:** `InventoryTransaction` deja de estar huérfano; todo cambio de stock pasa por un único servicio y queda registrado.

### Migración
- `..._recreate_inventory_transactions_ledger.php` (revisa/ajusta la tabla existente):
  ```
  business_id (FK, index)         inventory_item_id (FK, index)
  type: enum/string  { in, out, adjust, waste, sale, restock }
  quantity: integer (con signo, +entra / -sale)
  balance_after: integer          reason: string nullable
  source_type / source_id: nullableMorphs  (Appointment | Order | manual)
  user_id: FK nullable            timestamps
  ```

### Modelo
- `app/Models/InventoryTransaction.php` → `use BelongsToBusiness`; casts; relaciones `item()`, `source()` (morphTo), `user()`. **Inmutable**: no exponer `update()` en la lógica de negocio.

### Servicio (choke point único)
- `app/Services/InventoryService.php`
  ```php
  public function adjustStock(
      InventoryItem $item, int $delta, string $type,
      ?string $reason = null, ?Model $source = null, ?User $user = null
  ): InventoryTransaction
  // DB::transaction + lockForUpdate sobre el item:
  //   nuevoStock = max(0, item.stock + delta)  (o política de negativo si permites sobreconsumo)
  //   item.update(stock=nuevoStock)
  //   crea InventoryTransaction(quantity=delta, balance_after=nuevoStock, ...)
  ```

### Refactors (redirigir mutaciones existentes al servicio)
- `HealthSkillsService`/`GenericSkillProvider` descuento de insumos al agendar (hoy `->decrement('stock', $qty)`): pásalo por `InventoryService::adjustStock(..., type:'out', source:$appointment)`.
- `AdminTools/AdminWriteTools::updateStockTool` y `addInventoryItemTool`: escrituras de stock vía `InventoryService` (type `adjust`/`restock`/`in`).

### Reposición + reportes
- Tool Admin `restock_item` (`type:'restock'`, opcional `supplier`).
- Añade columna `supplier` nullable a `inventory_items` (migración corta) o guárdalo en `attributes` json.
- Reportes (controller + página Inertia o solo tools de lectura): valor total de inventario (`Σ stock*price`), consumo por periodo (agrupando `inventory_transactions`), kardex por item.

### Tests (primero)
- `InventoryLedgerTest`: cada `adjustStock` crea 1 fila y `balance_after` cuadra; concurrencia con `lockForUpdate` no pierde ajustes; agendar cita genera transacción `out` ligada al Appointment (source morph).

### Done
Toda mutación de stock deja rastro; el kardex reconstruye el stock actual sumando el ledger.

---

## FASE 2 — Pedidos + Pagos (online y presencial)

**Meta:** vender fuera de citas y cobrar de verdad (o marcar cobro en local).

### Migraciones
- `orders`:
  ```
  business_id (FK)   contact_id (FK nullable, walk-in)
  status: { draft, confirmed, paid, fulfilled, cancelled }
  payment_method: { online, presencial, none }
  payment_status: { pending, paid, failed }
  subtotal_cents, total_cents (integer)   notes nullable
  customer_info json   placed_at nullable   timestamps
  ```
- `order_items`:
  ```
  order_id (FK)   inventory_item_id (FK nullable)
  name (snapshot)   unit_price_cents   quantity   line_total_cents
  ```
- `payments`:
  ```
  business_id (FK)   payable_type / payable_id (morph → Order | Appointment)
  contact_id (FK nullable)   provider: { payphone, stripe, presencial, fake }
  method: { online, presencial }   amount_cents   currency
  status: { pending, paid, failed, cancelled }   external_ref nullable   paid_at nullable
  timestamps
  ```

### Modelos
- `Order` (`use BelongsToBusiness`, `items()`, `contact()`, `payments()` morphMany), `OrderItem`, `Payment` (`use BelongsToBusiness`, `payable()` morphTo).
- Al **confirmar** una Order (`OrderService::confirm(Order)`): por cada item, si es producto con stock propio → `InventoryService::adjustStock(delta:-qty, type:'sale', source:$order)`; si es servicio con `requiredSupplies` → descuenta cada insumo. Todo dentro de `DB::transaction`.

### Pagos — abstracción de pasarela
- `app/Payments/PaymentGateway.php` (interface): `createCharge(Payment $p): array` (devuelve `payment_url` o estado), `verifyWebhook(Request): ?Payment`.
- Implementaciones: `FakeGateway` (dev/tests, el simulado actual), `PayPhoneGateway` **o** `StripeGateway` (elige uno para v1).
- **Presencial:** no llama a pasarela. `PaymentService` crea `Payment(method:'presencial', status:'pending')` y responde instrucción de "pagar en el local"; el staff lo marca `paid` desde el dashboard/Admin tool `mark_payment_paid`.
- **Arregla el link muerto:** ruta real `GET /pay/{payment}` (página de pago/checkout) + webhook `POST /api/payments/{provider}/webhook` que valida firma y marca `Payment.status=paid` (idempotente por `external_ref`).
- Config: añade a `config/services.php` y `.env.example` las keys de la pasarela elegida.

### Refactor
- `PaymentService::generatePaymentLink()` → crea `Payment` real y devuelve URL a `/pay/{payment}` (no la URL inventada actual). El parámetro elige `online` vs `presencial`.
- Tool de cliente `generate_payment_link` → acepta `method` (online/presencial).

### Tests (primero)
- `OrderConfirmationTest`: confirmar descuenta stock y escribe ledger `sale`; stock insuficiente → error controlado, sin descuento parcial.
- `PaymentFlowTest`: online crea Payment `pending` + URL; webhook idempotente marca `paid` una sola vez; presencial no crea link y `mark_payment_paid` lo cierra.

### Done
Un negocio puede vender, descontar stock y cobrar online o en local; `/pay/{payment}` existe y funciona.

---

## FASE 3 — Recurso genérico + primer vertical enchufado

**Meta:** generalizar `Doctor`→`Resource` y demostrar el registro con un vertical no-salud.

### Migraciones (Doctor → Resource)
1. `create_resources_table`: `business_id, name, type (doctor|table|chair|station|room|staff), attributes json, capacity int nullable, is_active bool`.
2. `add_resource_id_to_appointments` (nullable, FK, index).
3. `backfill`: inserta un `Resource(type:'doctor')` por cada `Doctor`; copia `appointments.doctor_id → resource_id`.
4. `update_overlap_constraint` (Postgres): reemplaza el EXCLUDE para usar `COALESCE(resource_id, 0)` en vez de `doctor_id` (mismo patrón que `2026_08_04_000004_fix_doctor_appointment_overlap_constraint.php`).
5. Deja `doctor_id` un ciclo como nullable por compatibilidad; elimínalo en una migración posterior cuando todo apunte a `resource_id`.

### Código
- `Appointment`: relación `resource()` (y mantén `doctor()` temporalmente). `HealthSkillsService`/providers usan `resource_id`.
- `Resource` model (`use BelongsToBusiness`). `Doctor` queda como caso `type='doctor'` (puedes mantener el modelo `Doctor` como consulta filtrada o retirarlo gradualmente).
- `GenericSkillProvider`: `check_availability`/`reserve_slot` operan sobre `Resource` (filtrables por `type`).
- `RestaurantSkillProvider` (ejemplo): `promptSection()` con vocabulario de restaurante; tools = genéricas + `reserve_table` (Resource type='table', usa capacity) + `place_order` (menú = InventoryItems type service/product). Retail queda casi gratis (catálogo + place_order, sin reservas).

### Tests (primero)
- `ResourceReservationTest`: dos reservas mismo slot distinto resource OK; mismo resource solapado → rechazado (constraint + guard app).
- `RestaurantVerticalTest`: negocio `vertical='restaurant'` carga RestaurantSkillProvider; `place_order` descuenta insumos; `reserve_table` respeta capacidad/solape.

### Done
Un restaurante onboarded recibe un bot de menú/pedidos/mesas; el anti-solape es genérico por recurso.

---

## FASE 4 — Onboarding, seeding y dashboard por vertical

**Meta:** que crear un negocio de cualquier vertical quede usable de una.

### Código
- `OnboardingController::store()` (línea ~65, tras crear el Business): llama a un `VerticalSeeder::seed($business)` que siembra catálogo/persona/config por defecto según `vertical` (ej. restaurante: mesas como Resources + platillos demo; salud: servicios demo).
- Dashboard: mostrar módulos según `vertical` (Citas vs Pedidos vs ambos). El `AdminChatWidget` ya es agnóstico; añade tools Admin de lectura de **pedidos** y **reportes de inventario**.
- Actualiza `README.md` (corrige "Vue 3"→React y el alcance) y `.env.example` (keys de pasarela).

### Tests
- `OnboardingSeedingTest`: onboarding de cada vertical deja el negocio con su catálogo/recursos mínimos.

---

## Orden de construcción y dependencias

```
Fase 0 (registro)  ─────────────▶ desbloquea todo, riesgo bajo
      │
Fase 1 (ledger)  ──────┐         valor inmediato a consultorios
      │                 │
Fase 2 (pedidos+pagos) ◀┘        depende del ledger (Fase 1)
      │
Fase 3 (Resource+vertical) ◀─────depende del registro (0) y pedidos (2)
      │
Fase 4 (onboarding/seeding)      cierra el círculo
```

Recomendado: **0 → 1 → 2 → 3 → 4**. Cada fase es un PR independiente, CI verde, cobertura ≥75% (gate actual del `ci.yml`).

## Riesgos y mitigación
- **Refactor de `UaldoManagerService` (Fase 0):** cubierto por `HealthAiAgentTest`; si sigue verde sin editarlo, no rompiste nada.
- **Migración Doctor→Resource (Fase 3):** hazla en pasos aditivos (añadir `resource_id`, backfill, cambiar constraint) y borra `doctor_id` en una migración separada posterior. Nunca dropees columna y FK en el mismo PR que introduce la nueva.
- **Constraint EXCLUDE:** solo Postgres. Mantén el guard `lockForUpdate` en app para que SQLite (tests) siga protegido.
- **Idempotencia de pagos:** el webhook debe ser idempotente por `external_ref` (mismo patrón que el `wa_id` del webhook de WhatsApp).

## Antes de empezar (housekeeping)
1. **Commitea el trabajo de `fusion`** (hoy 64+ archivos sin versionar) para tener base limpia por PR.
2. Arranca cada fase con `git switch -c feat/fase-N-...` desde `fusion`.
