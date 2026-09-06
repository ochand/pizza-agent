<?php

namespace Database\Factories;

use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $items = [
            [
                'name' => 'Pepperoni',
                'size' => 'Large',
                'qty' => 1,
                'price' => 14.99,
            ],
        ];

        return [
            'customer_name' => $this->faker->name(),
            'phone' => $this->faker->numerify('##########'),
            'fulfillment_type' => $this->faker->randomElement(['pickup', 'delivery']),
            'address' => $this->faker->optional()->address(),
            'items' => $items,
            'total' => collect($items)->sum(fn (array $item) => $item['qty'] * $item['price']),
            'retell_call_id' => 'call_'.$this->faker->uuid(),
        ];
    }
}
