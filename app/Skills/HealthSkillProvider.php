<?php

namespace App\Skills;

use App\Models\Business;
use App\Models\Contact;
use App\Services\HealthSkillsService;

class HealthSkillProvider implements SkillProvider
{
    public function __construct(
        protected HealthSkillsService $healthSkillsService
    ) {}

    public function vertical(): string
    {
        return 'health';
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
- Tipo de negocio: Consultorio Médico / Salud.
- Objetivos: Atender amablemente a los pacientes por WhatsApp, responder sus dudas sobre servicios y precios, y agendar, reprogramar o cancelar citas médicas/odontológicas/estéticas.
- Vocabulario: Usar "paciente", "cita médica", "doctor/especialista" y "tratamiento/servicio".

REGLAS DE ACTUACIÓN DEL VERTICAL SALUD:
1. Sé siempre educado, profesional y empático. Usa un tono cálido y confiable.
2. Antes de confirmar una cita nueva, USA la herramienta `check_availability` para verificar si la fecha y hora sugeridas están libres.
3. Si la fecha y hora están disponibles, USA la herramienta `schedule_appointment` para registrar la cita.
4. Si el paciente pide reprogramar, usa `reschedule_appointment`. Si pide cancelar, usa `cancel_appointment`.
5. Si pregunta por precios o tratamientos, usa `search_services`. Si pide pagar/abonar, usa `generate_payment_link`.
6. Si el paciente expresa una emergencia grave o pide hablar con un humano, USA `transfer_to_human`.
7. Responde en español y mantén las respuestas concisas para WhatsApp (máximo 2-3 párrafos).
PROMPT;
    }
}
