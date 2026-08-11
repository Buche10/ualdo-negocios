<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Doctor;
use App\Models\InventoryItem;
use App\Models\Resource;
use Cknow\Money\Money;

class VerticalSeeder
{
    /**
     * Seed default catalog items, resources, and persona configuration per vertical.
     */
    public function seed(Business $business): void
    {
        BusinessContext::runInContext($business, function () use ($business) {
            match ($business->vertical) {
                'health', 'aesthetic' => $this->seedHealth($business),
                'restaurant' => $this->seedRestaurant($business),
                'retail' => $this->seedRetail($business),
                default => $this->seedGeneric($business),
            };
        });
    }

    protected function seedHealth(Business $business): void
    {
        // 1. Doctor y Recurso Doctor
        $doctor = Doctor::create([
            'business_id' => $business->id,
            'name' => 'Dr. Especialista',
            'specialty' => 'Consulta General',
            'is_active' => true,
        ]);

        Resource::create([
            'business_id' => $business->id,
            'name' => $doctor->name,
            'type' => 'doctor',
            'attributes' => ['specialty' => 'Consulta General'],
            'capacity' => 1,
            'is_active' => true,
        ]);

        // 2. Servicios demo
        InventoryItem::create([
            'business_id' => $business->id,
            'name' => 'Consulta Médica General',
            'description' => 'Evaluación inicial y diagnóstico médico',
            'type' => 'service',
            'price' => Money::USD(3500),
            'stock' => 0,
            'min_stock' => 0,
        ]);
    }

    protected function seedRestaurant(Business $business): void
    {
        // 1. Mesas como Recursos
        Resource::create([
            'business_id' => $business->id,
            'name' => 'Mesa 1 (2 personas)',
            'type' => 'table',
            'capacity' => 2,
            'is_active' => true,
        ]);

        Resource::create([
            'business_id' => $business->id,
            'name' => 'Mesa 2 (4 personas)',
            'type' => 'table',
            'capacity' => 4,
            'is_active' => true,
        ]);

        // 2. Menú demo
        InventoryItem::create([
            'business_id' => $business->id,
            'name' => 'Platillo Ejecutivo',
            'description' => 'Entrada, plato fuerte y bebida del día',
            'type' => 'product',
            'price' => Money::USD(850),
            'stock' => 50,
            'min_stock' => 5,
        ]);
    }

    protected function seedRetail(Business $business): void
    {
        InventoryItem::create([
            'business_id' => $business->id,
            'name' => 'Producto Demo',
            'description' => 'Artículo de muestra para venta rápida',
            'type' => 'product',
            'price' => Money::USD(1500),
            'stock' => 20,
            'min_stock' => 3,
        ]);
    }

    protected function seedGeneric(Business $business): void
    {
        Resource::create([
            'business_id' => $business->id,
            'name' => 'Estación de Atención 1',
            'type' => 'station',
            'capacity' => 1,
            'is_active' => true,
        ]);

        InventoryItem::create([
            'business_id' => $business->id,
            'name' => 'Servicio Estándar',
            'description' => 'Atención o consultoría general',
            'type' => 'service',
            'price' => Money::USD(2500),
            'stock' => 0,
            'min_stock' => 0,
        ]);
    }
}
