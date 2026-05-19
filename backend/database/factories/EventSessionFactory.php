<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Event;
use App\Models\EventSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EventSession> */
class EventSessionFactory extends Factory
{
    public function definition(): array
    {
        $startsAt = fake()->dateTimeBetween('+1 day', '+6 months');
        $endsAt = (clone $startsAt)->modify('+'.fake()->numberBetween(2, 5).' hours');

        return [
            'event_id' => Event::factory(),
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'doors_open_at' => (clone $startsAt)->modify('-30 minutes'),
            'status' => EventSession::STATUS_SCHEDULED,
        ];
    }
}
