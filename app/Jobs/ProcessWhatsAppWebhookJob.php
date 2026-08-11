<?php

namespace App\Jobs;

use App\Models\Business;
use App\Services\BusinessContext;
use App\Services\UaldoManagerService;
use App\Services\WhatsAppService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessWhatsAppWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Delete the job if its models no longer exist.
     */
    public bool $deleteWhenMissingModels = true;

    /**
     * Número de intentos antes de marcar el job como fallido.
     */
    public int $tries = 3;

    /**
     * Espera (en segundos) entre reintentos ante fallos transitorios de la API del LLM/WhatsApp.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    /**
     * Create a new job instance.
     *
     * @param  array<string, mixed>  $payload
     */
    public function __construct(protected array $payload)
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(UaldoManagerService $ualdoService, WhatsAppService $whatsapp): void
    {
        if (! isset($this->payload['object']) || $this->payload['object'] !== 'whatsapp_business_account' || ! isset($this->payload['entry']) || ! is_array($this->payload['entry'])) {
            return;
        }

        foreach ($this->payload['entry'] as $entry) {
            if (! isset($entry['changes']) || ! is_array($entry['changes'])) {
                continue;
            }

            foreach ($entry['changes'] as $change) {
                if (isset($change['value']['messages']) && is_array($change['value']['messages'])) {
                    $metadata = $change['value']['metadata'] ?? [];
                    $phoneNumberId = $metadata['phone_number_id'] ?? null;

                    // Resolver negocio correspondiente por phone_number_id de Meta Cloud API
                    $business = null;
                    if (! empty($phoneNumberId)) {
                        $business = Business::where('whatsapp_phone_number_id', $phoneNumberId)->first();
                    }
                    if (! $business) {
                        $business = Business::first();
                    }

                    $contactsData = $change['value']['contacts'][0] ?? [];
                    $profileName = $contactsData['profile']['name'] ?? null;

                    foreach ($change['value']['messages'] as $messageData) {
                        $senderPhone = $messageData['from'] ?? null;
                        $waId = $messageData['id'] ?? null;
                        $messageText = $messageData['text']['body'] ?? '';

                        if (! empty($senderPhone) && ! empty($messageText)) {
                            Log::info("ProcessWhatsAppWebhookJob procesando mensaje de {$senderPhone} ({$profileName}) para negocio '{$business?->name}' [ID: {$business?->id}]: {$messageText}");

                            // Procesar con IA + Tools en el contexto de negocio resuelto
                            BusinessContext::runInContext($business, function () use ($ualdoService, $whatsapp, $senderPhone, $messageText, $waId, $profileName) {
                                $reply = $ualdoService->processIncomingMessage($senderPhone, $messageText, 'whatsapp', $waId, $profileName);

                                // Enviar respuesta por WhatsApp
                                if (! empty($reply)) {
                                    $whatsapp->sendText($senderPhone, $reply);
                                }
                            });
                        }
                    }
                }
            }
        }
    }

    /**
     * Manejo cuando el job agota todos los reintentos.
     */
    public function failed(?\Throwable $exception): void
    {
        Log::error('ProcessWhatsAppWebhookJob falló tras agotar los reintentos.', [
            'error' => $exception?->getMessage(),
        ]);
    }
}
