<?php

namespace Tests\Feature;

use App\Integrations\DoblefiloConnector;
use App\Integrations\Dto\CanonicalMovement;
use App\Models\Business;
use App\Models\BusinessIntegration;
use App\Services\BusinessContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DoblefiloPushMovementTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;

    protected BusinessIntegration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create([
            'name' => 'Restaurante Push Test',
            'slug' => 'restaurante-push-test',
            'vertical' => 'restaurant',
        ]);

        BusinessContext::set($this->business);

        $this->integration = BusinessIntegration::create([
            'business_id' => $this->business->id,
            'provider' => 'doblefilo',
            'base_url' => 'https://doblefilo.vercel.app',
            'api_key' => 'push_secret_key_555',
            'enabled' => true,
        ]);
    }

    public function test_push_movement_sends_correct_payload_to_doblefilo(): void
    {
        Http::fake([
            'https://doblefilo.vercel.app/api/inventory/movements' => Http::response([
                'success' => true,
                'movementId' => 'mov_999888777',
            ], 201),
        ]);

        $connector = new DoblefiloConnector($this->integration);

        $movement = new CanonicalMovement(
            productExternalUid: '65a123b456c7890def123456',
            type: 'input',
            quantity: 20,
            unitSnapshot: 'kg',
            location: 'kitchen',
            notes: 'Registrado por Carlos Pérez vía Telegram Ualdo',
            referenceId: 'tx_12345'
        );

        $res = $connector->pushMovement($movement);

        $this->assertTrue($res['success']);
        $this->assertEquals('mov_999888777', $res['movementId']);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://doblefilo.vercel.app/api/inventory/movements'
                && $request->hasHeader('x-api-key', 'push_secret_key_555')
                && $request['productId'] === '65a123b456c7890def123456'
                && $request['movementType'] === 'input'
                && $request['quantity'] === 20
                && $request['unitSnapshot'] === 'kg'
                && $request['toLocation'] === 'kitchen'
                && $request['referenceType'] === 'manual_adjustment'
                && $request['notes'] === 'Registrado por Carlos Pérez vía Telegram Ualdo';
        });
    }
}
