<?php

namespace App\Console\Commands;

use App\Jobs\SyncInventoryFromDoblefiloJob;
use App\Models\BusinessIntegration;
use Illuminate\Console\Command;

class SyncDoblefiloCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'integrations:sync-doblefilo {business_id? : ID del negocio específico a sincronizar}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Despacha el job de sincronización de inventario desde Doble Filo.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $businessId = $this->argument('business_id');

        $query = BusinessIntegration::withoutGlobalScopes()
            ->where('provider', 'doblefilo')
            ->where('enabled', true);

        if (! empty($businessId)) {
            $query->where('business_id', (int) $businessId);
        }

        $integrations = $query->get();

        if ($integrations->isEmpty()) {
            $this->info('No se encontraron integraciones de Doble Filo activas para procesar.');

            return self::SUCCESS;
        }

        foreach ($integrations as $integration) {
            SyncInventoryFromDoblefiloJob::dispatch($integration->id);
            $this->info("Job de sincronización despachado para la integración #{$integration->id} (Business #{$integration->business_id}).");
        }

        return self::SUCCESS;
    }
}
