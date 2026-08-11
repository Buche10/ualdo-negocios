# Plan de conexión: Doble Filo → Ualdo → Run Food

> Objetivo: **puente automatizado**. Ualdo lee el inventario (y recetas) de Doble Filo, lo normaliza,
> y lo empuja a Run Food para facturación — eliminando el copiado manual.
> Ualdo queda además como espejo/canónico, así el inventario es visible para el bot de WhatsApp y el AdminChat.

---

## Hechos descubiertos (inspección real de Doble Filo)

- **Stack:** Next.js + **MongoDB**. Es un ERP de bodega completo (Productos, Inventario, Movimientos, Compras con flujo de aprobación, Control diario, Producción, Solicitudes internas).
- **SÍ tiene API interna**, pero autenticada por **cookie/sesión** (login humano). Falta **auth para máquina**.
- Endpoints vistos: `GET /api/inventory?page&limit`, `GET /api/categories`, `GET /api/families`, `GET /api/auth/me`, `GET /api/notifications`.
- Escala: **238 productos**, inventario **multi-ubicación** (warehouse / kitchen / lounge) con cantidades `reserved`.

### Modelo real de un producto (`/api/inventory`)
```json
{
  "_id": "6a501e04d64ca6f811cca38e",
  "code": "PRD-310577",
  "name": "Caldo de gallina en polvo",
  "unit": "unit",                         // unit | kg | package
  "productType": "raw_material",
  "storageType": "ambient",
  "tracksStock": true,
  "requiresWeightControl": false,
  "minStock": 0, "reorderPoint": 0,
  "isActive": true,
  "category": { "name": "Condimentos Y Aderezos", "slug": "..." },
  "inventory": { "total": 48, "available": 48, "reserved": 0,
                 "warehouse": 0, "kitchen": 48, "lounge": 0, "locations": ["kitchen"] },
  "status": "ok", "isBelowMinStock": false, "isBelowReorderPoint": false
}
```

---

## Arquitectura objetivo

```
Doble Filo (MongoDB)          UALDO (bridge, Laravel multi-tenant)              Run Food
  GET /api/inventory   ──▶  DoblefiloConnector.pull()                            (facturación)
  (x-api-key)               → DTO canónico → upsert InventoryItem (espejo)
                            → detecta deltas → RunFoodConnector.push()  ──▶  POST import (x-api-key)
                            IntegrationMapping (idempotencia) · SyncRun (logs)
                            SyncInventoryJob (cron + on-demand)
```

Principio: Ualdo **no** reemplaza a ninguna app (rol = tubería). Pero guarda un **espejo canónico**
en `InventoryItem` para trazabilidad, deltas y para exponerlo al bot/AdminChat.

---

## Componentes nuevos en Ualdo

### Tablas
- **`business_integrations`** (por tenant): `business_id`, `provider` (`doblefilo`|`runfood`), `base_url`,
  `api_key` (**cifrada**, cast `encrypted`), `enabled`, `config` json, `last_synced_at`.
- **`integration_mappings`**: `business_id`, `provider`, `external_id` (Doble Filo `code`/`_id` o id de Run Food),
  `inventory_item_id` (FK Ualdo), `external_hash` (para detectar cambios), timestamps. **Único** por (`business_id`,`provider`,`external_id`).
- **`sync_runs`** (logs): `business_id`, `direction`, `status`, `pulled`, `upserted`, `pushed`, `errors` json, `duration_ms`, `started_at`, `finished_at`.

### Código (`app/Integrations/`)
- **`Connector`** (interface): `pull(): array` (fuente) / `push(array $items): SyncResult` (destino).
- **`Dto\CanonicalItem`**: forma normalizada (ver mapeo abajo).
- **`DoblefiloConnector implements Connector`**: cliente HTTP `GET /api/inventory` con header `x-api-key`,
  **paginado** (`limit` alto, recorre `meta.pages`), mapea a `CanonicalItem`.
- **`RunFoodConnector implements Connector`**: `push()` → `POST {base_url}/api/inventory/import` con `x-api-key`.
- **`IntegrationSyncService`**: orquesta pull → upsert canónico → detectar deltas (por `external_hash`) → push; escribe `SyncRun`.
- **`SyncInventoryJob`** (ShouldQueue): corre `IntegrationSyncService` dentro de `BusinessContext::runInContext($business, …)`.
  Idempotente, con `tries`/`backoff` como los webhooks actuales.

### Mapeo Doble Filo → Canónico → Ualdo/Run Food

| Doble Filo | Canónico | Ualdo `InventoryItem` | Nota |
|-----------|----------|----------------------|------|
| `code` (PRD-xxxxxx) | `sku` | `attributes['sku']` + `external_ref` | Clave de idempotencia |
| `name` | `name` | `name` | |
| `unit` (unit/kg/package) | `unit` | `unit` | |
| `category.name` | `category` | `attributes['category']` | |
| `inventory.available` | `stock` | `stock` | **Decisión: total vs available vs por-ubicación** |
| `minStock` / `reorderPoint` | `min_stock` | `min_stock` | |
| `productType`,`requiresWeightControl` | `meta{}` | `attributes` | Para peso/insumo |
| (precio/costo — no visto aún) | `price` | `price` (cents) | **Falta descubrir endpoint de costos** |

---

## Fases

### Fase 0 — Descubrimiento y contratos (prerequisito, sin código en Ualdo aún)
1. **Doble Filo:** añadir auth de máquina — middleware que acepte `x-api-key` (además de la cookie) en `/api/inventory`, `/api/categories`, y el endpoint de **recetas/producción** (aún por localizar: revisar `/dashboard/production` y `/dashboard/products` → capturar su `/api/...`). Cambio chico en el Next.js.
2. **Run Food:** confirmar stack y **crear/exponer** `POST /api/inventory/import` (upsert por `sku`) con `x-api-key`. Definir su JSON de entrada.
3. Definir el **campo de stock** a sincronizar (recomendado: `inventory.available`) y la **autoridad de stock** (ver Decisiones).

### Fase 1 — Andamiaje de integración en Ualdo
- Migraciones `business_integrations`, `integration_mappings`, `sync_runs`.
- Interface `Connector` + `CanonicalItem` + `SyncResult`.
- Config por negocio (guardar base_url + api_key cifrada) desde el dashboard o un `AdminTool`.
- **Tests:** guardar/leer credenciales cifradas; scope multi-tenant fail-closed.

### Fase 2 — DoblefiloConnector (pull → espejo canónico)
- `pull()` paginado con `x-api-key`; upsert idempotente en `InventoryItem` por `code` vía `integration_mappings`.
- `InventoryService::adjustStock` para reflejar diferencias como transacciones `adjust` (fuente = `integration:doblefilo`).
- **Valor inmediato:** el inventario de Doble Filo queda visible en Ualdo (WhatsApp/AdminChat) aunque Run Food aún no esté.
- **Tests:** `Http::fake` del `/api/inventory`; 238 ítems → upsert sin duplicar; segunda corrida sin cambios = 0 escrituras.

### Fase 3 — RunFoodConnector (push)
- `push()` → `POST /api/inventory/import` de los ítems con delta (por `external_hash`).
- Registrar el id que devuelva Run Food en `integration_mappings` para futuras actualizaciones.
- **Tests:** `Http::fake` del import; solo se empujan deltas; reintento idempotente no duplica.

### Fase 4 — Orquestación + observabilidad
- `SyncInventoryJob` en el scheduler (ej. cada 15 min) + botón "Sincronizar ahora" (dashboard/AdminChat).
- `SyncRun` con conteos y errores; alerta (Telegram al staff) si una corrida falla.
- Manejo de errores parcial: un ítem que falla no aborta toda la corrida.

### Fase 5 — Recetas + reconciliación (y gancho anti-robo)
- Sincronizar recetas de Doble Filo → `service_supplies` (BOM) de Ualdo.
- **Reconciliación:** cruzar movimientos/cierres de Doble Filo con facturación de Run Food para detectar faltantes.
- Gancho futuro: eventos de **sensores de caja** (apertura/duración) → si hubo apertura sin movimiento/venta en la ventana → alerta de faltante (ver conversación de sensores).

---

## Decisiones abiertas (necesito tu input antes de Fase 2/3)

1. **Autoridad de stock.** Doble Filo es la fuente (tú lo dijiste), pero Run Food **también descuenta al facturar**.
   ¿El sync es **one-way** (Doble Filo pisa a Run Food) o Run Food descuenta por su cuenta y solo recibe **altas/nuevos productos**? Si es one-way total, las ventas de Run Food se perderían en cada sync. **Recomendado:** Doble Filo manda el **catálogo y reposiciones**; las **bajas por venta** las lleva Run Food. Hay que separar "sync de catálogo/stock entrante" de "consumo por venta".
2. **Qué figura de stock** enviar: `total`, `available`, o por ubicación (warehouse/kitchen/lounge). Recomendado: `available`.
3. **Precio/costo:** no vi el campo en `/api/inventory`. ¿Run Food factura con precio propio, o Ualdo debe traer el costo/precio desde Doble Filo? (Falta ubicar ese endpoint.)
4. **Run Food:** ¿stack? ¿puede exponer el `POST /api/inventory/import`? Sin eso, el push cae a vías feas (BD directa o archivo).

---

## Seguridad (reusar patrones ya probados en el repo)
- API keys **cifradas** en BD (`encrypted` cast); nunca en logs.
- Todo el sync corre en `BusinessContext::runInContext(...)`; los modelos siguen fail-closed.
- Idempotencia por `external_id` + `external_hash` (mismo espíritu que `wa_id`/`external_ref` de los webhooks).
- Rate-limit y timeout en los clientes HTTP; reintentos con backoff en el Job.

## Orden recomendado
**Fase 0 (contratos) → 1 (andamiaje) → 2 (pull, valor ya) → 3 (push) → 4 (orquestación) → 5 (recetas/reconciliación).**
La Fase 2 sola ya te da el inventario de Doble Filo dentro de Ualdo; el puente completo cierra en Fase 3–4.
