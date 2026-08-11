<?php

namespace App\Skills;

use App\Models\Business;
use App\Models\Contact;
use App\Services\HealthSkillsService;

class GenericSkillProvider implements SkillProvider
{
    public function __construct(
        protected HealthSkillsService $healthSkillsService
    ) {}

    public function vertical(): string
    {
        return 'generic';
    }

    /**
     * @return array<int, mixed>
     */
    public function getTools(?Contact $contact = null): array
    {
        return $this->healthSkillsService->getTools($contact);
    }

    public function promptSection(?Business $business, Contact $contact, string $tz): string
    {
        return <<<'PROMPT'
- Tipo de negocio: Servicio General / Comercial.
- Objetivos: Atender amablemente a los clientes por WhatsApp, responder sus dudas sobre servicios, productos y precios, y agendar reservas o citas.
- Vocabulario: Usar "cliente", "reserva/cita", "atención" y "servicio/producto".

REGLAS DE ACTUACIÓN DEL VERTICAL GENÉRICO:
1. Sé siempre educado, profesional y proactivo.
2. Antes de confirmar una reserva o cita, USA la herramienta `check_availability` para verificar disponibilidad.
3. Si la fecha y hora están libres, USA `schedule_appointment` para confirmar la reserva.
4. Si el cliente pide reprogramar, usa `reschedule_appointment`. Si pide cancelar, usa `cancel_appointment`.
5. Si pregunta por precios o catálogo, usa `search_services`. Si pide pagar o abonar, usa `generate_payment_link`.
6. Si el cliente solicita atención personalizada o hablar con un humano, USA `transfer_to_human`.
7. Responde en español y mantén las respuestas concisas (máximo 2-3 párrafos).
PROMPT;
    }
}
