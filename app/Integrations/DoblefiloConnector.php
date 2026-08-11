<?php

namespace App\Integrations;

use App\Integrations\Contracts\WritableConnector;
use App\Integrations\Dto\CanonicalItem;
use App\Integrations\Dto\CanonicalMovement;
use App\Models\BusinessIntegration;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class DoblefiloConnector implements WritableConnector
{
    public function __construct(
        protected BusinessIntegration $integration
    ) {}

    public function provider(): string
    {
        return 'doblefilo';
    }

    /**
     * @return array<int, CanonicalItem>
     */
    public function pull(): array
    {
        $baseUrl = rtrim($this->integration->base_url, '/');
        $apiKey = $this->integration->api_key;
        $items = [];
        $page = 1;
        $totalPages = 1;

        do {
            $headers = [];
            if (! empty($apiKey)) {
                $headers['x-api-key'] = $apiKey;
            }

            $response = Http::withHeaders($headers)
                ->timeout(20)
                ->get("{$baseUrl}/api/inventory", [
                    'page' => $page,
                    'limit' => 100,
                ]);

            if (! $response->successful()) {
                throw new RuntimeException("Doblefilo API request failed with status {$response->status()}: {$response->body()}");
            }

            $json = $response->json();
            $rows = $json['data'] ?? [];
            $totalPages = (int) ($json['meta']['pages'] ?? 1);

            foreach ($rows as $row) {
                $items[] = $this->mapItem($row);
            }

            $page++;
        } while ($page <= $totalPages);

        return $items;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected function mapItem(array $row): CanonicalItem
    {
        $externalId = (string) ($row['code'] ?? $row['_id'] ?? $row['id'] ?? '');
        $name = (string) ($row['name'] ?? 'Producto Desconocido');
        $unit = (string) ($row['unit'] ?? 'unit');
        $category = null;
        if (isset($row['categoryName']) && is_string($row['categoryName'])) {
            $category = $row['categoryName'];
        } elseif (isset($row['category'])) {
            if (is_string($row['category'])) {
                $category = $row['category'];
            } elseif (is_array($row['category']) && isset($row['category']['name']) && is_string($row['category']['name'])) {
                $category = $row['category']['name'];
            }
        }

        $inventory = $row['inventory'] ?? [];
        $available = (float) ($inventory['available'] ?? $inventory['total'] ?? 0);
        $stockExact = $available;
        $stock = (int) round($available);

        $minStock = (int) ($row['minStock'] ?? $row['reorderPoint'] ?? 0);
        $isActive = isset($row['isActive']) ? (bool) $row['isActive'] : true;

        $meta = [
            'productType' => $row['productType'] ?? 'raw_material',
            'requiresWeightControl' => $row['requiresWeightControl'] ?? false,
            'locations' => $inventory['locations'] ?? [],
            'reorderPoint' => $row['reorderPoint'] ?? null,
        ];

        $externalUid = isset($row['_id']) ? (string) $row['_id'] : (isset($row['id']) ? (string) $row['id'] : null);

        return new CanonicalItem(
            externalId: $externalId,
            name: $name,
            unit: $unit,
            category: $category,
            stock: $stock,
            stockExact: $stockExact,
            minStock: $minStock,
            isActive: $isActive,
            meta: $meta,
            externalUid: $externalUid,
        );
    }

    /**
     * Push a movement to Doble Filo API POST /api/inventory/movements
     *
     * @return array<string, mixed>
     */
    public function pushMovement(CanonicalMovement $movement): array
    {
        $baseUrl = rtrim($this->integration->base_url, '/');
        $apiKey = $this->integration->api_key;

        $headers = [];
        if (! empty($apiKey)) {
            $headers['x-api-key'] = $apiKey;
        }

        $locationKey = in_array($movement->type, ['output', 'adjustment_out'], true) ? 'fromLocation' : 'toLocation';

        $payload = [
            'productId' => $movement->productExternalUid,
            'movementType' => $movement->type,
            'quantity' => $movement->quantity,
            'unitSnapshot' => $movement->unitSnapshot,
            $locationKey => $movement->location ?: 'kitchen',
            'referenceType' => 'manual_adjustment',
            'referenceId' => (string) ($movement->referenceId ?? ''),
            'notes' => $movement->notes,
        ];

        $response = Http::withHeaders($headers)
            ->timeout(20)
            ->post("{$baseUrl}/api/inventory/movements", $payload);

        if (! $response->successful()) {
            throw new RuntimeException("Doblefilo pushMovement failed with status {$response->status()}: {$response->body()}");
        }

        return $response->json() ?? [];
    }
}
