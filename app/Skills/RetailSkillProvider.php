<?php

namespace App\Skills;

use App\Models\Business;
use App\Models\Contact;

class RetailSkillProvider extends GenericSkillProvider
{
    public function vertical(): string
    {
        return 'retail';
    }

    public function promptSection(?Business $business, Contact $contact, string $tz): string
    {
        return <<<'PROMPT'
- Tipo de negocio: Tienda / Comercio Minorista (Retail).
- Objetivos: Atender a los clientes por WhatsApp, responder sobre productos del catálogo, verificar existencias y tomar pedidos.
- Vocabulario: Usar "cliente", "producto", "compra" y "pedido".

REGLAS DE ACTUACIÓN DEL VERTICAL RETAIL:
1. Ayuda a los clientes a encontrar productos en el catálogo.
2. Confirma disponibilidad de stock antes de concretar una venta.
PROMPT;
    }
}
