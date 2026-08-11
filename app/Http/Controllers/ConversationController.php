<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\Message;
use App\Services\WhatsAppService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ConversationController extends Controller
{
    /**
     * Lista las conversaciones del negocio paginadas sin N+1.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $businessId = $user?->business_id;

        $conversations = Contact::where('business_id', $businessId)
            ->with(['messages' => fn ($q) => $q->latest()->limit(1)])
            ->orderBy('updated_at', 'desc')
            ->paginate(15);

        return Inertia::render('Inbox/Index', [
            'conversations' => $conversations,
        ]);
    }

    /**
     * Muestra el detalle de los mensajes de una conversación con un paciente/cliente.
     */
    public function show(Request $request, int $contactId): Response
    {
        $contact = Contact::withoutGlobalScopes()->findOrFail($contactId);
        $user = $request->user();

        if ($contact->business_id !== $user?->business_id) {
            abort(403, 'No tienes acceso a conversaciones de otro negocio.');
        }

        $messages = Message::where('contact_id', $contact->id)
            ->orderBy('created_at', 'asc')
            ->get();

        return Inertia::render('Inbox/Show', [
            'contact' => $contact,
            'messages' => $messages,
        ]);
    }

    /**
     * Responde al paciente vía WhatsApp usando las credenciales del negocio y pausa el bot por 24h.
     */
    public function reply(Request $request, int $contactId, WhatsAppService $whatsAppService): RedirectResponse
    {
        $contact = Contact::withoutGlobalScopes()->findOrFail($contactId);
        $user = $request->user();

        if ($contact->business_id !== $user?->business_id) {
            abort(403, 'No tienes permiso para responder mensajes de otro negocio.');
        }

        $validated = $request->validate([
            'message' => 'required|string|max:2000',
        ]);

        $res = $whatsAppService->sendText($contact->phone_number, $validated['message']);

        if (! $res || isset($res['error'])) {
            $errMsg = $res['error']['message'] ?? 'Error al enviar mensaje vía WhatsApp API.';

            return redirect()->back()->withErrors(['reply' => $errMsg]);
        }

        // Registrar el mensaje saliente y pausar el bot por 24h para handoff humano
        Message::create([
            'business_id' => $user->business_id,
            'contact_id' => $contact->id,
            'role' => 'assistant',
            'content' => $validated['message'],
        ]);

        $contact->update(['bot_paused_until' => now()->addHours(24)]);

        return redirect()->back()->with('message', 'Respuesta enviada con éxito. El bot ha sido pausado por 24 horas para este contacto.');
    }
}
