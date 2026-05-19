<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Event;
use App\Models\Media;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Media> */
class MediaFactory extends Factory
{
    public function definition(): array
    {
        return [
            'mediable_type' => Event::class,
            'mediable_id' => Event::factory(),
            'type' => Media::TYPE_IMAGE,
            'path' => 'seed-pool/img-16-9-01.jpg',
            'mime_type' => 'image/jpeg',
            'width' => 1920,
            'height' => 1080,
            'duration_seconds' => null,
            'position' => 0,
            'alt_text' => fake()->sentence(6),
        ];
    }
}
