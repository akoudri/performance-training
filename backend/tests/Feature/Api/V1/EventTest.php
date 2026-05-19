<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\EventSession;
use App\Models\Organizer;
use App\Models\TicketCategory;
use App\Models\User;

beforeEach(function (): void {
    // Pool de référence : 3 events publiés + 1 draft.
    $orgUser = User::factory()->organizer()->create();
    $organizer = Organizer::factory()->create(['user_id' => $orgUser->id]);

    $this->events = Event::factory(3)->create(['organizer_id' => $organizer->id]);
    Event::factory()->draft()->create(['organizer_id' => $organizer->id]);

    foreach ($this->events as $event) {
        $session = EventSession::factory()->create(['event_id' => $event->id]);
        TicketCategory::factory(2)->create(['event_session_id' => $session->id]);
    }
});

it('GET /events → 200 + ne liste que les published', function (): void {
    $response = $this->getJson('/api/v1/events');

    $response->assertOk()
        ->assertJsonStructure([
            'data' => [['id', 'slug', 'title', 'category', 'organizer', 'media']],
        ]);

    expect($response->json('data'))->toHaveCount(3);
});

it('GET /events?city → filtre', function (): void {
    $this->events->first()->update(['city' => 'Marseille']);

    $response = $this->getJson('/api/v1/events?city=Marseille');
    expect($response->json('data'))->toHaveCount(1);
});

it('GET /events?category → filtre', function (): void {
    $this->events->first()->update(['category' => 'concert']);
    $this->events->skip(1)->first()->update(['category' => 'festival']);

    $response = $this->getJson('/api/v1/events?category=concert');
    expect(count($response->json('data')))->toBeGreaterThanOrEqual(1);
});

it('GET /events/{slug} → 200 avec détail', function (): void {
    $event = $this->events->first();

    $this->getJson("/api/v1/events/{$event->slug}")
        ->assertOk()
        ->assertJsonStructure([
            'data' => ['id', 'slug', 'title', 'organizer', 'media'],
        ]);
});

it('GET /events/{slug} → 404 si introuvable', function (): void {
    $this->getJson('/api/v1/events/i-do-not-exist')->assertNotFound();
});

it('GET /events/{slug}/sessions → 200', function (): void {
    $event = $this->events->first();

    $this->getJson("/api/v1/events/{$event->slug}/sessions")
        ->assertOk()
        ->assertJsonStructure([
            'data' => [['id', 'starts_at', 'ticket_categories']],
        ]);
});
