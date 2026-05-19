<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\EventSession;
use App\Models\TicketCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TicketCategory> */
class TicketCategoryFactory extends Factory
{
    private const NAMES = ['Carré Or', 'Catégorie 1', 'Catégorie 2', 'Catégorie 3', 'Tribune'];

    public function definition(): array
    {
        return [
            'event_session_id' => EventSession::factory(),
            'name' => fake()->randomElement(self::NAMES),
            'price_cents' => fake()->numberBetween(2000, 25000),
            'quota' => fake()->numberBetween(50, 500),
            'sold' => 0,
        ];
    }
}
