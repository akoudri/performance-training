<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Event;
use App\Models\Organizer;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Event> */
class EventFactory extends Factory
{
    /** Pool de villes françaises pour la cohérence (cf. resonance-spec.md §7). */
    private const FR_CITIES = [
        'Paris', 'Lyon', 'Marseille', 'Bordeaux', 'Lille', 'Nantes', 'Toulouse',
        'Strasbourg', 'Nice', 'Montpellier', 'Rennes', 'Reims', 'Le Havre',
        'Saint-Étienne', 'Toulon', 'Grenoble', 'Dijon', 'Angers', 'Nîmes',
        'Villeurbanne', 'Saint-Denis', 'Aix-en-Provence', 'Brest', 'Limoges',
        'Tours', 'Amiens', 'Perpignan', 'Metz', 'Besançon', 'Orléans',
    ];

    public function definition(): array
    {
        $title = fake()->sentence(4, true);

        return [
            'organizer_id' => Organizer::factory(),
            'slug' => Str::slug($title).'-'.fake()->unique()->numberBetween(1, 9_999_999),
            'title' => $title,
            'description' => fake()->paragraphs(4, asText: true),
            'category' => fake()->randomElement(Event::CATEGORIES),
            'city' => fake()->randomElement(self::FR_CITIES),
            'country' => 'FR',
            'venue_name' => fake()->company().' '.fake()->randomElement(['Hall', 'Stadium', 'Théâtre', 'Zénith']),
            'cover_image_path' => null,
            'published_at' => now()->subDays(fake()->numberBetween(0, 90)),
            'status' => Event::STATUS_PUBLISHED,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn () => [
            'status' => Event::STATUS_DRAFT,
            'published_at' => null,
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn () => ['status' => Event::STATUS_ARCHIVED]);
    }
}
