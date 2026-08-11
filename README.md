# UaldoBusiness — Plataforma Multi-Vertical Genérica (AI Receptionist & Operations)

Motor de atención automatizada por WhatsApp/Telegram y gestión operativa multi-tenant para consultorios médicos, restaurantes, tiendas de retail y negocios de servicios.

## 🎯 Plataforma Multi-Vertical Genérica
> Arquitectura agnóstica basada en **SkillProviders por vertical** (Salud, Restaurantes, Retail, Servicios General), con **recursos genéricos (`Resource`)**, **ledger inmutable de inventario (`InventoryTransaction`)**, **ciclo de vida de pedidos (`Order`, `OrderItem`)** y **pasarela de pagos online y presenciales (`Payment`, PayPhone/Fake)**.

---

## 🛠️ Stack Tecnológico
- **Chasis**: Laravel 12 + Breeze + Inertia JS (React).
- **Orquestación IA**: `echolabsdev/prism` con Tool-Calling (DeepSeek / OpenAI) y arquitectura 3-Layer System Prompt.
- **Base de Datos**: PostgreSQL / SQLite (Multi-tenant fail-closed por `BusinessContext`).
- **Integraciones**: Meta WhatsApp Cloud API + Telegram Bot API + PayPhone Payment Gateway + Google Calendar Sync.

---

## 🚦 Fases de Desarrollo Multi-Vertical
- [x] **Fase 0**: Registro de Skills por Vertical (`SkillRegistry`, `HealthSkillProvider`, `GenericSkillProvider`).
- [x] **Fase 1**: Inventario Operativo (Ledger inmutable `InventoryTransaction`, `InventoryService` con `lockForUpdate`).
- [x] **Fase 2**: Pedidos + Pagos (`Order`, `OrderItem`, `Payment`, `PaymentGateway`, `/pay/{payment}` y webhooks idempotentes).
- [x] **Fase 3**: Recurso Genérico + Vertical Restaurante (`Resource`, anti-solape por recurso, `RestaurantSkillProvider`).
- [x] **Fase 4**: Onboarding, Seeding & Dashboard por Vertical (`VerticalSeeder`, Admin AI tools de lectura de pedidos y Kardex).

---

## ⚙️ Configuración y Despliegue

1. `composer install && npm install && npm run build`
2. Copia `.env.example` a `.env` y completa las keys (ver bloque **Ualdo — Recepcionista AI**):
   - **IA**: `DEEPSEEK_API_KEY` (principal) y/o `OPENAI_API_KEY` (fallback).
   - **WhatsApp Cloud API**: `WHATSAPP_PHONE_ID`, `WHATSAPP_ACCESS_TOKEN`, `WHATSAPP_VERIFY_TOKEN`, `WHATSAPP_APP_SECRET`.
   - **Google Calendar**: `GOOGLE_CALENDAR_ID`, `GOOGLE_SERVICE_ACCOUNT_JSON`.
3. `php artisan key:generate && php artisan migrate --force`
4. **Producción**: usa `DB_CONNECTION=pgsql`. La migración `..._add_appointment_overlap_exclusion_constraint` activa un guard `EXCLUDE` que impide dobles reservas a nivel de BD (no disponible en SQLite).
5. Arranca el worker de cola (procesa los webhooks): `php artisan queue:work`
6. Registra el scheduler en cron para los recordatorios: `* * * * * php artisan schedule:run`

### Webhook de WhatsApp
- Verificación (GET) y recepción (POST): `/api/whatsapp/webhook`
- La firma `X-Hub-Signature-256` se valida si `WHATSAPP_APP_SECRET` está configurado.

### Tests
```
php artisan test
```

