<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Organizer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Organizer> */
class OrganizerFactory extends Factory
{
    public function definition(): array
    {
        $companyName = fake()->company();

        return [
            'user_id' => User::factory()->organizer(),
            'company_name' => $companyName,
            'slug' => Str::slug($companyName).'-'.fake()->unique()->numberBetween(1, 999_999),
            'description' => fake()->paragraph(3),
            'logo_path' => null,
        ];
    }
}
