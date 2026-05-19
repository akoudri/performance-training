<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\EventSession;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\TicketCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Ticket> */
class TicketFactory extends Factory
{
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'ticket_category_id' => TicketCategory::factory(),
            'event_session_id' => EventSession::factory(),
            'code' => Str::uuid()->toString(),
            'holder_name' => fake()->name(),
            'status' => Ticket::STATUS_VALID,
            'pdf_path' => null,
        ];
    }
}
