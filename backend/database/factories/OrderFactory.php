<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Order> */
class OrderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'total_cents' => fake()->numberBetween(2000, 50000),
            'status' => Order::STATUS_PAID,
            'paid_at' => now()->subDays(fake()->numberBetween(0, 60)),
            'payment_reference' => Str::uuid()->toString(),
        ];
    }

    public function pending(): static
    {
        return $this->state(fn () => [
            'status' => Order::STATUS_PENDING,
            'paid_at' => null,
            'payment_reference' => null,
        ]);
    }
}
