<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\Organizer;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $orgUser = User::factory()->organizer()->create();
    $organizer = Organizer::factory()->create(['user_id' => $orgUser->id]);
    $this->event = Event::factory()->create(['organizer_id' => $organizer->id]);
});

it('POST /favorites/{event:id} → 201 (auth requis)', function (): void {
    Sanctum::actingAs($this->user);

    $this->postJson("/api/v1/favorites/{$this->event->id}")
        ->assertCreated();

    $this->assertDatabaseHas('favorites', [
        'user_id' => $this->user->id,
        'event_id' => $this->event->id,
    ]);
});

it('POST /favorites/{event:id} → 401 sans token', function (): void {
    $this->postJson("/api/v1/favorites/{$this->event->id}")
        ->assertUnauthorized();
});

it('POST /favorites/{event:id} → idempotent', function (): void {
    Sanctum::actingAs($this->user);

    $this->postJson("/api/v1/favorites/{$this->event->id}")->assertCreated();
    $this->postJson("/api/v1/favorites/{$this->event->id}")->assertCreated();

    expect(\DB::table('favorites')
        ->where('user_id', $this->user->id)
        ->where('event_id', $this->event->id)
        ->count()
    )->toBe(1);
});

it('DELETE /favorites/{event:id} → 200', function (): void {
    Sanctum::actingAs($this->user);
    \DB::table('favorites')->insert([
        'user_id' => $this->user->id,
        'event_id' => $this->event->id,
        'created_at' => now(),
    ]);

    $this->deleteJson("/api/v1/favorites/{$this->event->id}")->assertOk();

    $this->assertDatabaseMissing('favorites', [
        'user_id' => $this->user->id,
        'event_id' => $this->event->id,
    ]);
});
