# UaldoBusiness — Recepcionista AI para Consultorios de Salud (MVP)

Motor de atención automatizada por WhatsApp para consultorios médicos, odontológicos y centros de estética.

## 🎯 Alcance Congelado (Single Job MVP)
> **Un solo trabajo:** Atender clientes por WhatsApp, responder sobre servicios/tarifas del consultorio, verificar disponibilidad y agendar/cancelar citas sin doble reserva.

### 🚫 Fuera de Alcance del MVP (Aparcado en rama):
- E-commerce / Creación de órdenes de compra (`Order`, `OrderItem`).
- Campañas de marketing masivo (`Campaign`).
- Multi-verticales adicionales (Restaurantes, Retail, etc.).

---

## 🛠️ Stack Tecnológico
- **Chasis**: Laravel 12 + Breeze + Inertia JS (Vue 3).
- **Orquestación IA**: `echolabsdev/prism` (^0.100.1) con Tool-Calling (DeepSeek / OpenAI).
- **Base de Datos**: PostgreSQL / SQLite (Contact, Appointment, InventoryItem, AiSkill).
- **Integraciones**: Meta WhatsApp Cloud API v20.0 + Google Calendar Sync.

---

## 🚦 Fases de Desarrollo
- [x] **Fase 0**: Baseline y Control de Alcance (Git setup & manifest).
- [x] **Fase 1**: Trasplante de Cerebro (Identidad Contact, Threading y Tool-calling Prism).
- [x] **Fase 2**: Core Tools (Disponibilidad, Agendar/Reprogramar/Cancelar anti-doble-reserva, Servicios, Handoff).
- [x] **Fase 3**: Flujo WhatsApp End-to-End (webhook asíncrono + firma HMAC + idempotencia).
- [x] **Fase 4**: Verticalización Salud (Google Calendar Sync, Recordatorios Anti-No Show idempotentes y LOPDP).

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

