<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderControllerTest extends TestCase
{
    use RefreshDatabase;

    private array $validPayload;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.retell.api_secret' => 'test-secret']);

        $this->validPayload = [
            'customer_name' => 'Jane Doe',
            'phone' => '5551234567',
            'fulfillment_type' => 'pickup',
            'items' => [
                ['name' => 'Margherita', 'size' => 'Medium', 'qty' => 2, 'price' => 12.50],
            ],
            'total' => 25.00,
            'retell_call_id' => 'call_123',
        ];
    }

    public function test_it_creates_an_order_with_a_valid_api_key(): void
    {
        $response = $this->postJson('/api/orders', $this->validPayload, [
            'X-Api-Key' => 'test-secret',
        ]);

        $response->assertCreated()->assertJsonStructure(['confirmation_number']);

        $this->assertDatabaseHas('orders', [
            'customer_name' => 'Jane Doe',
            'fulfillment_type' => 'pickup',
        ]);

        $order = Order::first();
        $this->assertSame('Margherita', $order->items[0]['name']);
    }

    public function test_it_rejects_requests_without_a_valid_api_key(): void
    {
        $response = $this->postJson('/api/orders', $this->validPayload, [
            'X-Api-Key' => 'wrong-secret',
        ]);

        $response->assertStatus(401);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_it_requires_an_address_for_delivery_orders(): void
    {
        $payload = array_merge($this->validPayload, ['fulfillment_type' => 'delivery']);

        $response = $this->postJson('/api/orders', $payload, [
            'X-Api-Key' => 'test-secret',
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('address');
    }

    public function test_it_validates_required_fields(): void
    {
        $response = $this->postJson('/api/orders', [], [
            'X-Api-Key' => 'test-secret',
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors([
            'customer_name', 'phone', 'fulfillment_type', 'items', 'total',
        ]);
    }
}
