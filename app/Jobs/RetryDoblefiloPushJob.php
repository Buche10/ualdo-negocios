<?php

namespace App\Jobs;

use App\Integrations\DoblefiloConnector;
use App\Integrations\Dto\CanonicalMovement;
use App\Models\Business;
use App\Models\BusinessIntegration;
use App\Models\IntegrationMapping;
use App\Models\InventoryTransaction;
use App\Services\BusinessContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class RetryDoblefiloPushJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 30, 60];

    public function __construct(
        public InventoryTransaction $transaction
    ) {}

    public function handle(): void
    {
        if ($this->transaction->mirror_status === 'synced') {
            return;
        }

        /** @var Business|null $business */
        $business = $this->transaction->business;
        if (! $business) {
            return;
        }

        BusinessContext::runInContext($business, function () {
            /** @var BusinessIntegration|null $integration */
            $integration = BusinessIntegration::where('provider', 'doblefilo')
                ->where('enabled', true)
                ->first();

            if (! $integration) {
                return;
            }

            /** @var IntegrationMapping|null $mapping */
            $mapping = IntegrationMapping::where('provider', 'doblefilo')
                ->where('inventory_item_id', $this->transaction->inventory_item_id)
                ->first();

            if (! $mapping || empty($mapping->external_uid)) {
                return;
            }

            $payload = $this->transaction->mirror_payload ?? [];
            if (empty($payload)) {
                return;
            }

            $movement = new CanonicalMovement(
                productExternalUid: $mapping->external_uid,
                type: (string) ($payload['type'] ?? 'input'),
                quantity: (float) ($payload['quantity'] ?? 0),
                unitSnapshot: (string) ($payload['unit'] ?? 'unit'),
                location: (string) ($payload['location'] ?? 'kitchen'),
                notes: (string) ($payload['notes'] ?? ''),
                referenceId: $this->transaction->id
            );

            try {
                $connector = new DoblefiloConnector($integration);
                $connector->pushMovement($movement);

                $this->transaction->update(['mirror_status' => 'synced']);
                Log::info("RetryDoblefiloPushJob: Successfully synced transaction #{$this->transaction->id} to Doble Filo.");
            } catch (Throwable $e) {
                $this->transaction->update(['mirror_status' => 'failed']);
                Log::warning("RetryDoblefiloPushJob: Retry failed for transaction #{$this->transaction->id}: ".$e->getMessage());

                throw $e;
            }
        });
    }
}
