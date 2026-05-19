<?php

declare(strict_types=1);

use App\Mail\OrderConfirmationMail;
use App\Models\Event;
use App\Models\EventSession;
use App\Models\Order;
use App\Models\Organizer;
use App\Models\TicketCategory;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    Mail::fake();
    // Disque s3 fake → pas d'upload MinIO réel pendant les tests.
    Storage::fake('s3');

    $this->user = User::factory()->create();

    $organizerUser = User::factory()->organizer()->create();
    $organizer = Organizer::factory()->create(['user_id' => $organizerUser->id]);
    $event = Event::factory()->create(['organizer_id' => $organizer->id]);
    $this->session = EventSession::factory()->create(['event_id' => $event->id]);
    $this->category = TicketCategory::factory()->create([
        'event_session_id' => $this->session->id,
        'price_cents' => 5000,
        'quota' => 100,
        'sold' => 0,
    ]);
});

it('POST /orders → 401 sans token', function (): void {
    $this->postJson('/api/v1/orders', [])->assertUnauthorized();
});

it('POST /orders → 201 + tickets créés + email synchrone envoyé', function (): void {
    Sanctum::actingAs($this->user);

    $response = $this->postJson('/api/v1/orders', [
        'event_session_id' => $this->session->id,
        'items' => [[
            'ticket_category_id' => $this->category->id,
            'quantity' => 2,
            'holder_name' => 'Test Holder',
        ]],
    ]);

    $response->assertCreated()
        ->assertJsonStructure([
            'data' => ['id', 'status', 'total_cents', 'tickets'],
        ])
        ->assertJsonPath('data.status', 'paid')
        ->assertJsonPath('data.total_cents', 10000);

    expect($response->json('data.tickets'))->toHaveCount(2);

    Mail::assertSent(OrderConfirmationMail::class, fn ($mail) => $mail->hasTo($this->user->email));
});

it('POST /orders → 422 si quota insuffisant', function (): void {
    Sanctum::actingAs($this->user);
    $this->category->update(['quota' => 1, 'sold' => 1]); // sold = quota → indisponible

    $this->postJson('/api/v1/orders', [
        'event_session_id' => $this->session->id,
        'items' => [[
            'ticket_category_id' => $this->category->id,
            'quantity' => 1,
            'holder_name' => 'X',
        ]],
    ])->assertStatus(422);
});

it('GET /orders/{id} → 200 si propriétaire, 403 sinon', function (): void {
    $order = Order::factory()->create(['user_id' => $this->user->id]);
    $other = User::factory()->create();

    Sanctum::actingAs($this->user);
    $this->getJson("/api/v1/orders/{$order->id}")->assertOk();

    Sanctum::actingAs($other);
    $this->getJson("/api/v1/orders/{$order->id}")->assertForbidden();
});

it('GET /me/tickets → 200', function (): void {
    Sanctum::actingAs($this->user);

    $this->getJson('/api/v1/me/tickets')
        ->assertOk()
        ->assertJsonStructure(['data']);
});
