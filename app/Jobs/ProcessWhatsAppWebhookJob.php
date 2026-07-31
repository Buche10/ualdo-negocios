<?php

namespace App\Jobs;

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
     *
     * @var bool
     */
    public bool $deleteWhenMissingModels = true;

    /**
     * Create a new job instance.
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
        if (!isset($this->payload['object']) || $this->payload['object'] !== 'whatsapp_business_account') {
            return;
        }

        foreach ($this->payload['entry'] as $entry) {
            foreach ($entry['changes'] as $change) {
                if (isset($change['value']['messages'])) {
                    $messageData = $change['value']['messages'][0];
                    $contactsData = $change['value']['contacts'][0] ?? [];

                    $senderPhone = $messageData['from'] ?? null;
                    $waId = $messageData['id'] ?? null;
                    $messageText = $messageData['text']['body'] ?? '';
                    $profileName = $contactsData['profile']['name'] ?? null;

                    if (!empty($senderPhone) && !empty($messageText)) {
                        Log::info("ProcessWhatsAppWebhookJob procesando mensaje de {$senderPhone} ({$profileName}): {$messageText}");

                        // Procesar con IA + Tools
                        $reply = $ualdoService->processIncomingMessage($senderPhone, $messageText, 'whatsapp', $waId);

                        // Enviar respuesta por WhatsApp
                        if (!empty($reply)) {
                            $whatsapp->sendText($senderPhone, $reply);
                        }
                    }
                }
            }
        }
    }
}
