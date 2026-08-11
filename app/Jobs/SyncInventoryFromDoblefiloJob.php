<?php

namespace App\Jobs;

use App\Models\Business;
use App\Models\BusinessIntegration;
use App\Services\BusinessContext;
use App\Services\IntegrationSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncInventoryFromDoblefiloJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function __construct(
        public int $businessIntegrationId
    ) {}

    public function handle(IntegrationSyncService $syncService): void
    {
        /** @var BusinessIntegration|null $integration */
        $integration = BusinessIntegration::withoutGlobalScopes()->find($this->businessIntegrationId);

        if (! $integration || ! $integration->enabled) {
            return;
        }

        /** @var Business|null $business */
        $business = $integration->business;

        BusinessContext::runInContext($business, function () use ($syncService, $integration) {
            $syncService->pull($integration);
        });
    }
}
