# Plan de desarrollo — Registro de inventario por Telegram (Ualdo → Doble Filo)

> Objetivo: los empleados reportan por Telegram en lenguaje natural ("llegaron 20 kg de bondiola",
> "usamos 3 piñas", "quedan 5 fundas") y Ualdo registra el movimiento **en Ualdo (fuente)** y lo **espeja en Doble Filo**.
> Decisiones cerradas: destino = **en ambos**; API de escritura de Doble Filo = **investigar** (hecho, ver abajo).

---

## 0. Hechos descubiertos (reconocimiento no destructivo)

- **Endpoint de movimientos:** `GET /api/inventory/movements` (anidado; por eso `/api/movements` daba 404).
  La escritura será casi seguro `POST /api/inventory/movements`.
- **Shape de un movimiento:**
  `productId` (**ObjectId de Mongo**), `movementType`, `quantity`, `unitSnapshot`, `fromLocation`,
  `toLocation`, `referenceType` (`manual_adjustment`), `referenceId`, `notes`, `performedBy` (userId), `movementDate`.
- **Tipos:** `input` (entrada), `output` (salida), `transfer`, y subtipos `adjustment_in` / `adjustment_out`.
- **Ubicaciones:** `warehouse` (Bodega), `kitchen`, `lounge`.
- ⚠️ **Los movimientos referencian el producto por `_id` de Mongo, NO por `code`.** El pull actual guarda `code`/`sku`; hay que **guardar también el `_id`**.
- **Auth:** los endpoints de escritura requieren sesión (los de lectura de productos ya dan 401 sin cookie). Para server-to-server, Doble Filo necesita **auth de máquina (`x-api-key`)** en el POST.

> No se creó ni modificó nada en Doble Filo durante el reconocimiento.

---

## 1. Arquitectura

```
Empleado (Telegram)
   │  "usamos 3 piñas"
   ▼
TelegramWebhookController ──▶ StaffInventorySkill (tools Prism)      ← ya existe webhook + identidad staff
   │                              │
   │                      resuelve producto (match difuso sobre el ESPEJO de 238)  ← ya existe (pull)
   │                              │  ambiguo → pregunta ("¿Merma Bondiola o Bondiola entera?")
   │                              ▼
   │                      confirma con botones inline ("Salida −3 Piña, quedan 2→−1 ✅/❌")  ← ya usas inline buttons + ApprovalGate
   │                              ▼
   │         ┌────────────────────┴────────────────────┐
   │         ▼ (fuente)                                 ▼ (espejo)
   │   InventoryService.adjustStock                DoblefiloConnector.pushMovement
   │   (ledger Ualdo, type in/out/adjust)          POST /api/inventory/movements (x-api-key)
   └─────────────────────────────────────────────────────────────────────────────
```

Regla de consistencia: Ualdo aplica el delta a su ledger **y** lo empuja a Doble Filo.
El siguiente `pull` traerá el stock ya actualizado de Doble Filo → converge (hash sin cambio). Ver §4.

---

## 2. Componentes nuevos / a modificar en Ualdo

### 2.1 Enhancement al pull (prerequisito para escribir)
- Guardar el `_id` de Mongo de cada producto durante el pull.
  - `CanonicalItem`: añadir `externalUid` (el `_id`).
  - `DoblefiloConnector::mapItem`: `externalUid = $row['_id']`.
  - Persistir en `integration_mappings` (nueva columna `external_uid`) **o** en `attributes['doblefilo_id']`.
  - Test: tras pull, cada mapping tiene su `external_uid`.

### 2.2 Escritura en Doble Filo
- `app/Integrations/Dto/CanonicalMovement.php`: `externalUid, movementType, quantity, location, notes`.
- `DoblefiloConnector::pushMovement(CanonicalMovement): array` → `POST {base_url}/api/inventory/movements`
  con `x-api-key`; mapea a `{ productId, movementType, quantity, fromLocation, referenceType:'manual_adjustment', notes, performedBy }`.
  Maneja error/rethrow (para reintento del Job).
- (Ampliar el `interface Connector` con `pushMovement` **o** una interface aparte `WritableConnector` para no forzar a otros connectors.)

### 2.3 Servicio de registro (orquesta fuente + espejo)
- `app/Services/StaffInventoryService.php`:
  ```php
  public function register(User $staff, string $externalId /*sku*/, int|float $qty, string $kind /*in|out|count*/, ?string $location = null): array
  ```
  - Resuelve el `InventoryItem` + su mapping (para `external_uid`).
  - `kind=count` → calcula delta contra el stock actual (`adjustment_in/out`).
  - `InventoryService::adjustStock(...)` (fuente, ledger, `source` = el movimiento/registro).
  - `DoblefiloConnector::pushMovement(...)` (espejo). Si el push falla → registrar y **encolar reintento**, sin revertir el ledger de Ualdo (Ualdo es la fuente).
  - Devuelve resumen legible para responder por Telegram.

### 2.4 Skill de inventario para staff (tools Prism)
Añadir al agente de staff (junto a las tools de tareas/memorias existentes):
- `registrar_entrada(producto, cantidad, ubicacion?)` → `kind=in`
- `registrar_consumo(producto, cantidad, ubicacion?)` → `kind=out`
- `ajustar_conteo(producto, cantidad_final, ubicacion?)` → `kind=count`
- `buscar_producto(texto)` → para desambiguar antes de confirmar.
Prompt del skill: vocabulario de bodega, **siempre confirmar antes de escribir**, pedir aclaración si el producto o la cantidad son ambiguos, respetar unidad (kg vs unidad).

### 2.5 Confirmación (reusar lo que ya hay)
- Reutilizar `ApprovalGate` + `PendingAction` + botones inline de Telegram (ya probados en `TelegramNutgramIntegrationTest`).
- La tool propone el movimiento → responde con botones ✅/❌ → al confirmar, se ejecuta `StaffInventoryService::register`. Token single-use = idempotencia del registro.

---

## 3. Matching texto → producto
- Fuzzy sobre el espejo (`InventoryItem` de ese negocio): por `name` y `sku`, normalizando acentos/mayúsculas.
- Si hay 1 match claro → proponer. Si hay varios → listar y preguntar. Si ninguno → decir que no existe (¿crear? → fase futura).
- Unidad: tomar la del producto (`attributes['unit']`); si el empleado da otra, advertir.

---

## 4. Consistencia e idempotencia (lo delicado)
- **Doble ejecución:** el `PendingAction` es single-use atómico → un confirm = un registro.
- **Convergencia con el pull:** Ualdo empuja el delta a Doble Filo; el próximo pull ve el stock ya actualizado → no hay doble conteo (Ualdo fuerza `stock = disponible Doble Filo`).
- **Push fallido:** el ledger de Ualdo ya quedó (es la fuente); el movimiento se **reencola** para reintentar el espejo. Marcar en el registro `mirror_status` (pending/synced/failed) para reconciliación.
- **Orden pull vs push:** si un pull corre justo después del ledger pero antes del push, podría "revertir" el stock de Ualdo al valor viejo de Doble Filo. Mitigación: hacer push **síncrono** dentro del mismo registro (antes de responder al empleado), o pausar el pull mientras hay pushes pendientes.

---

## 5. Prerequisitos en Doble Filo (repo aparte)
1. **Auth de máquina (`x-api-key`)** en `POST /api/inventory/movements` (y idealmente en los GET que hoy dan 401).
2. **Confirmar el contrato exacto del POST**: valores válidos de `movementType`, campos requeridos, y **cómo se setea `performedBy`** (¿del token, o en el body?).
3. **Decidir `performedBy`**: un **usuario de servicio "Ualdo"** en Doble Filo, o mapear cada empleado de Telegram a un usuario de Doble Filo.

---

## 6. Fases y tests (TDD, con `Http::fake`)

```
A. Pull v2        → CanonicalItem.externalUid + columna external_uid + test
B. Escritura      → CanonicalMovement + DoblefiloConnector::pushMovement + test (Http::fake del POST)
C. Registro       → StaffInventoryService (fuente+espejo, mirror_status) + tests (in/out/count, push fallido reencola)
D. Skill staff    → tools Prism + matching + prompt + tests de resolución/ambigüedad
E. Confirmación   → wiring ApprovalGate + inline buttons + test end-to-end (mensaje → confirm → ledger + push)
F. Cierre         → pint + phpstan + pest verde; doc + .env
```

**Tests clave:**
- `pushMovement` arma el body correcto (productId = `_id`, no `code`) y manda `x-api-key`.
- Registro `out` de "3 piñas": ledger `out` + push con `movementType:output, quantity:3`.
- `count` (quedan 5): calcula delta contra stock actual y usa `adjustment_in/out`.
- Producto ambiguo → el agente pide aclaración (no escribe).
- Confirmación single-use: dos confirms = un solo registro.
- Push fallido → ledger persiste, `mirror_status=failed`, reencola.
- Multi-tenant: empleado del negocio A no puede tocar inventario del B (scope fail-closed).

---

## 7. Decisiones abiertas
- **D1 · `performedBy`:** usuario de servicio único vs mapeo por empleado. Recomendado v1: **usuario de servicio "Ualdo"**, con el nombre real del empleado en `notes`.
- **D2 · Ubicación por defecto:** si el empleado no la dice, ¿`kitchen`, `warehouse`, o preguntar? Recomendado: default configurable por negocio (`business.settings`), y preguntar solo si es transfer.
- **D3 · Confirmación:** ¿siempre, o solo para salidas/ajustes? Recomendado: **siempre** en v1 (escribe en dos sistemas reales).
- **D4 · Producto inexistente:** si el empleado nombra algo que no está en el catálogo, ¿crear en el acto o rechazar? Recomendado v1: **rechazar** y avisar; alta de producto = fase futura.

## Prerequisito para pasar a código
Que Doble Filo exponga `POST /api/inventory/movements` con `x-api-key` y me confirmes el contrato (D1 incluido).
Mientras tanto, A–E se desarrollan y testean con `Http::fake`.
```
