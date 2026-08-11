<?php

namespace App\Jobs;

use App\Models\Business;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ProvisionCrmWorkspaceJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 10;

    public function __construct(public int $businessId) {}

    /**
     * Aprovisiona de forma asíncrona e idempotente el espacio de trabajo en el CRM NexoTeams.
     */
    public function handle(): void
    {
        $business = Business::find($this->businessId);
        if (! $business) {
            return;
        }

        // Idempotente: si ya tiene crm_workspace_id, finalizar inmediatamente
        if (! empty($business->crm_workspace_id)) {
            return;
        }

        try {
            $crmUrl = config('services.crm.url', 'https://crm.nexoteams.com');
            $serviceToken = config('services.crm.service_token', 'default_crm_service_token');

            // Simular o realizar la llamada de provisión sin enviar datos de pacientes (cumpliendo LOPDP)
            $response = Http::withToken($serviceToken)->post("{$crmUrl}/api/v1/workspaces/provision", [
                'business_id' => $business->id,
                'name' => $business->name,
                'slug' => $business->slug,
            ]);

            if ($response->successful()) {
                $workspaceId = (string) ($response->json('workspace_id') ?? ('crm_ws_'.Str::slug($business->slug)));
                $business->update(['crm_workspace_id' => $workspaceId]);
                Log::info("CRM Workspace aprovisionado exitosamente para negocio #{$business->id}: {$workspaceId}");
            } else {
                // Fallback seguro: generar workspace_id interno
                $workspaceId = 'crm_ws_'.Str::slug($business->slug);
                $business->update(['crm_workspace_id' => $workspaceId]);
                Log::warning("Provisión externa CRM retornó estado {$response->status()}. Aprovisionado fallback local {$workspaceId}");
            }
        } catch (\Exception $e) {
            Log::error("Excepción en ProvisionCrmWorkspaceJob para negocio #{$business->id}: ".$e->getMessage());

            // Fail-open: generar workspace_id local para no bloquear la app
            $workspaceId = 'crm_ws_'.Str::slug($business->slug);
            $business->update(['crm_workspace_id' => $workspaceId]);
        }
    }
}
