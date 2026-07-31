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
- [ ] **Fase 1**: Trasplante de Cerebro (Identidad Contact, Threading y Spike Tool-calling Prism).
- [ ] **Fase 2**: 4 Core Tools (Disponibilidad, Agendamiento anti-doble-reserva, Servicios, Handoff).
- [ ] **Fase 3**: Flujo WhatsApp End-to-End & Settings del Consultorio.
- [ ] **Fase 4**: Verticalización Salud (Google Calendar Sync, Recordatorios Anti-No Show y LOPDP).

