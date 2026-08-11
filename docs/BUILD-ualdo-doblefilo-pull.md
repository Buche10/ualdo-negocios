# Plan de desarrollo — Conexión Ualdo ← Doble Filo (pull de inventario)

> Alcance de ESTE plan: **solo** traer inventario de Doble Filo a Ualdo (espejo canónico).
> El push a Run Food y las recetas quedan **fuera** (fases posteriores).
> Flujo TDD: test primero → implementación → `pint` + `phpstan` + `pest` verde por sub-fase.

---

## 0. Hechos base (ya verificados)

**Doble Filo** (Next.js + MongoDB):
- API interna real: `GET /api/inventory?page&limit` → `{ success, data[], summary, meta{page,limit,total,pages} }`. Hoy **autenticada por cookie**.
- Producto: `code` (PRD-xxxxxx), `name`, `unit` (unit|kg|package), `productType` (raw_material), `categoryName`, `minStock`, `reorderPoint`, `isActive`, `requiresWeightControl`, `inventory{ total, available, reserved, warehouse, kitchen, lounge, locations[] }`.
- Escala: 238 productos, stock **fraccionario** en kg (ej. `6.92`).

**Ualdo** (`inventory_items`): columnas `name, description, type, supplier, price(int centavos), stock(int), min_stock(int), attributes(json)`.
- ⚠️ **No existe columna `unit`** → la unidad va en `attributes['unit']`.
- ⚠️ `stock` es **integer** → el stock fraccionario de Doble Filo se guarda exacto en `attributes` y se redondea para `stock` (ver Decisión D1).

**Decisiones cerradas por el usuario:**
- Doble Filo = catálogo + reposiciones (fuente); Run Food lleva las bajas por venta.
- No se trae precio ni recetas desde Doble Filo (Run Food ya los tiene).
- Productos de Doble Filo (`raw_material`) → en Ualdo `type = 'supply'` (insumo).

---

## 1. Prerequisitos en Doble Filo (repo aparte, no este)

1. **Auth de máquina:** middleware que acepte header `x-api-key` (además de la cookie) en `GET /api/inventory`. Cambio chico en el Next.js.
2. Entregar: `base_url` (`https://doblefilo.vercel.app`) y una `api_key`.
3. (Diferido) localizar el endpoint de recetas/producción para una fase futura.

> Mientras tanto, todo el lado Ualdo se desarrolla y testea con `Http::fake()` usando el JSON real de arriba. No bloquea.

---

## 2. Entregables en Ualdo

### Migraciones (3)
`business_integrations`
```
id, business_id (FK cascade), provider (string), base_url (string),
api_key (text, nullable, cast encrypted), enabled (bool, default true),
config (json, nullable), last_synced_at (timestamp, nullable), timestamps
UNIQUE (business_id, provider)
```
`integration_mappings`
```
id, business_id (FK cascade), provider (string), external_id (string),
inventory_item_id (FK inventory_items, nullable, nullOnDelete),
external_hash (string, nullable), timestamps
UNIQUE (business_id, provider, external_id)   ·   INDEX (provider, external_id)
```
`sync_runs`
```
id, business_id (FK cascade), provider, direction (default 'pull'),
status (running|success|partial|failed), pulled, created_count, updated_count,
skipped (unsigned ints, default 0), errors (json, nullable),
duration_ms (unsigned int, nullable), started_at, finished_at (timestamps, nullable), timestamps
```

### Modelos (3) — todos `use BelongsToBusiness`
- `BusinessIntegration`: casts `api_key => encrypted`, `enabled => boolean`, `config => array`, `last_synced_at => datetime`.
- `IntegrationMapping`: relación `inventoryItem()` (BelongsTo).
- `SyncRun`: casts de conteos a integer, `errors => array`, fechas a datetime.

### DTO — `app/Integrations/Dto/CanonicalItem.php`
```php
final class CanonicalItem {
    public function __construct(
        public string $externalId,   // code PRD-xxxxxx
        public string $name,
        public string $unit,         // unit|kg|package
        public ?string $category,
        public int $stock,           // redondeado
        public float $stockExact,    // exacto (kg)
        public int $minStock,
        public bool $isActive,
        public array $meta = [],     // productType, requiresWeightControl, locations, reorderPoint
    ) {}

    public function hash(): string { // detecta cambios; incluye stockExact
        return hash('sha256', implode('|', [
            $this->name, $this->unit, (string) $this->category,
            $this->stockExact, $this->minStock, $this->isActive ? '1' : '0',
        ]));
    }
}
```

### Contrato — `app/Integrations/Contracts/Connector.php`
```php
interface Connector {
    public function provider(): string;
    /** @return array<int, CanonicalItem> */
    public function pull(): array;
}
```

### `app/Integrations/DoblefiloConnector.php`
- Constructor recibe `BusinessIntegration`.
- `pull()`: bucle paginado `GET {base_url}/api/inventory?page&limit=100` con `Http::withHeaders(['x-api-key'=>...])->timeout(20)`; recorre hasta `meta.pages`; si `failed()` → `throw RuntimeException`. Mapea cada `data[]` a `CanonicalItem`.
- `map(array $row)`: `available = inventory.available ?? total ?? 0`; `stock = (int) round(available)`; unit/category/minStock/isActive/meta según los campos reales.

### `app/Services/IntegrationSyncService.php` (el motor)
```php
public function pull(BusinessIntegration $integration): SyncRun
```
Algoritmo (asume `BusinessContext` activo):
1. Crea `SyncRun` (status `running`, `started_at`).
2. `$items = (new DoblefiloConnector($integration))->pull();`
3. Por cada item (try/catch individual — un fallo no aborta la corrida):
   - Busca `IntegrationMapping` por (provider, external_id).
   - **Skip** si existe y `external_hash === item.hash()` → `skipped++`.
   - **Update** si existe con item vinculado: aplica cambios (name, attributes) y ajusta stock (delta = `item.stock - inv.stock`) vía `InventoryService::adjustStock(inv, delta, 'adjust', 'Sync Doble Filo', source: $integration)`; refresca hash; `updated_count++`.
   - **Create** si no hay mapping: `InventoryItem::create(type:'supply', stock:0, min_stock, attributes)` → `adjustStock(+item.stock)` para el snapshot inicial → crea `IntegrationMapping`; `created_count++`.
4. `pulled = count(items)`. `integration->update(last_synced_at)`. Cierra `SyncRun` (`success` o `partial` si hubo errores, con `errors[]` y `duration_ms`).
5. En excepción global (ej. API caída) → `SyncRun` status `failed`.

> El stock SIEMPRE pasa por `InventoryService::adjustStock` (choke point + ledger `inventory_transactions`), coherente con la Fase 1 ya construida.

### `attributes` que se escriben en el InventoryItem
```
{ unit, category, sku: code, external_source: 'doblefilo',
  available_exact: stockExact, productType, requiresWeightControl, locations, reorderPoint }
```

### Job — `app/Jobs/SyncInventoryFromDoblefiloJob.php`
```php
public function __construct(public int $businessIntegrationId) {}
public function handle(IntegrationSyncService $svc): void {
    $integration = BusinessIntegration::withoutGlobalScopes()->find($this->businessIntegrationId);
    if (! $integration || ! $integration->enabled) return;
    BusinessContext::runInContext($integration->business, fn () => $svc->pull($integration));
}
```
`ShouldQueue`, `tries=3`, `backoff=[10,30,60]` (mismo patrón que los webhooks).

### Entrada práctica — comando artisan
`app/Console/Commands/SyncDoblefiloCommand.php` → `integrations:sync-doblefilo {business_id}`: despacha el Job. Útil para correr a mano y para el scheduler (se registra en `routes/console.php` **deshabilitado/comentado** hasta tener credenciales reales).

### Config de credenciales
Por ahora se crea el `BusinessIntegration` (base_url + api_key) vía seeder/tinker o un `AdminTool` mínimo. UI de configuración = fase posterior (fuera de alcance).

---

## 3. Mapeo Doble Filo → Ualdo

| Doble Filo | CanonicalItem | InventoryItem | Nota |
|-----------|---------------|---------------|------|
| `code` | `externalId` | `attributes['sku']` + `integration_mappings.external_id` | Clave idempotente |
| `name` | `name` | `name` | |
| `unit` | `unit` | `attributes['unit']` | No hay columna `unit` |
| `categoryName` | `category` | `attributes['category']` | |
| `inventory.available` | `stock`/`stockExact` | `stock` (round) + `attributes['available_exact']` | Ver D1 |
| `minStock` | `minStock` | `min_stock` | |
| `isActive` | `isActive` | (ver D2) | |
| `requiresWeightControl`,`productType`,`locations` | `meta` | `attributes` | |
| — (precio) | — | `price` = 0 | Run Food tiene el precio |
| — (tipo) | — | `type` = `'supply'` | raw_material = insumo |

---

## 4. Plan de tests (TDD, con `Http::fake`)

**`tests/Feature/DoblefiloConnectorTest.php`**
- `test_pull_maps_products_to_canonical_items`: fake 1 página con los 3 productos reales → assert cantidad, `name`, `unit`, `stock` redondeado (6.92 → 7), `stockExact` (6.92), `category`.
- `test_pull_paginates_until_last_page`: fake `meta.pages=2` con datos distintos por página → assert que recorre ambas.
- `test_pull_throws_on_api_failure`: fake 500 → espera excepción.
- `test_pull_sends_api_key_header`: assert que el request llevó `x-api-key`.

**`tests/Feature/DoblefiloSyncTest.php`** (en `BusinessContext`)
- `test_first_sync_creates_items_and_mappings`: fake N productos → `pull()` → assert `InventoryItem` creados con `type=supply`, stock correcto, `IntegrationMapping` creado, `SyncRun.status=success`, `created_count=N`.
- `test_stock_change_flows_through_ledger`: sync, luego cambia `available` en el fake, re-sync → `updated_count=1` y existe `InventoryTransaction` con el delta.
- `test_idempotent_second_run_skips_unchanged`: dos syncs con misma data → 2ª corrida `skipped=N`, sin `InventoryItem` nuevos.
- `test_partial_failure_records_errors_but_continues`: un producto con data inválida → `status=partial`, `errors[]` no vacío, el resto sí se procesa.
- `test_multitenant_isolation`: negocio A y B con su propio integration → los items de A no se ven desde B (scope fail-closed).

**`tests/Feature/SyncDoblefiloJobTest.php`**
- `test_job_loads_integration_without_context_and_runs_in_context`: sin `BusinessContext` activo, el Job resuelve el integration con `withoutGlobalScopes` y sincroniza. (Reproduce el patrón del webhook de pagos.)
- `test_disabled_integration_is_skipped`.

Meta de cobertura: ≥80% del código nuevo (gate CI actual: 75%).

---

## 5. Decisiones abiertas (resolver antes de codear)

- **D1 · Stock fraccionario.** `InventoryItem.stock` es integer; Doble Filo tiene kg (6.92).
  Recomendado v1: `stock = round(available)` + `attributes['available_exact']` con el valor real.
  Alternativa (mayor): migrar `stock`/`min_stock` a `decimal(12,3)` — toca `InventoryService`, `OrderService` y tests existentes. **A tu criterio.**
- **D2 · Productos inactivos (`isActive=false`).** Opciones: (a) importar igual pero marcar en attributes; (b) forzar `stock=0`; (c) omitir. Recomendado: (a).
- **D3 · Productos borrados en Doble Filo.** Un item que desaparece del origen: ¿se marca "huérfano" en Ualdo o se ignora? Recomendado v1: ignorar (no borrar en Ualdo); reconciliación en fase posterior.
- **D4 · Frecuencia de sync.** On-demand (comando/botón) primero; cron cada 15 min cuando haya credenciales estables.

---

## 6. Sub-fases y orden

```
A. Data layer  → 3 migraciones + 3 modelos + tests de modelo/scope
B. Connector   → CanonicalItem + Connector + DoblefiloConnector + DoblefiloConnectorTest (Http::fake)
C. Sync engine → IntegrationSyncService + DoblefiloSyncTest (upsert idempotente + ledger)
D. Entrada     → Job + comando artisan + SyncDoblefiloJobTest
E. Cierre      → pint + phpstan + pest verde; doc + .env.example (nota de credenciales)
```

**Definición de "hecho":** con `Http::fake` de los 238 productos, una corrida crea el espejo en Ualdo; una 2ª corrida no duplica; cambiar un stock genera una transacción en el ledger; y el inventario de Doble Filo queda visible para el bot de WhatsApp y el AdminChat — sin tocar Run Food todavía.

## Prerequisito para pasar a código
Confirmar **D1** (stock fraccionario) y tener la **`api_key` de Doble Filo** (o decidir arrancar solo con `Http::fake` y credenciales después).
```
