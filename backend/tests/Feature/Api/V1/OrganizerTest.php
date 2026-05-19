<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\EventSession;
use App\Models\Media;
use App\Models\Organizer;
use App\Models\TicketCategory;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    Storage::fake('s3');

    $this->orgUser = User::factory()->organizer()->create();
    $this->organizer = Organizer::factory()->create(['user_id' => $this->orgUser->id]);
    $this->event = Event::factory()->create(['organizer_id' => $this->organizer->id]);
    $this->session = EventSession::factory()->create(['event_id' => $this->event->id]);
    TicketCategory::factory(2)->create(['event_session_id' => $this->session->id]);
});

// ---------- Authorization ---------------------------------------------------

it('routes /organizer → 403 si user non organizer', function (): void {
    Sanctum::actingAs(User::factory()->create()); // visitor par défaut

    $this->getJson('/api/v1/organizer/stats')->assertForbidden();
});

it('routes /organizer → 401 sans token', function (): void {
    $this->getJson('/api/v1/organizer/stats')->assertUnauthorized();
});

// ---------- Stats / sales-chart -------------------------------------------

it('GET /organizer/stats → 200 + 4 KPIs', function (): void {
    Sanctum::actingAs($this->orgUser);

    $this->getJson('/api/v1/organizer/stats')
        ->assertOk()
        ->assertJsonStructure([
            'data' => ['today_orders', 'month_revenue_cents', 'fill_rate', 'active_events'],
        ]);
});

it('GET /organizer/sales-chart → 200', function (): void {
    Sanctum::actingAs($this->orgUser);

    $this->getJson('/api/v1/organizer/sales-chart')
        ->assertOk()
        ->assertJsonStructure(['data']);
});

// ---------- CRUD events ---------------------------------------------------

it('GET /organizer/events → 200 + liste de mes events', function (): void {
    Sanctum::actingAs($this->orgUser);

    $this->getJson('/api/v1/organizer/events')
        ->assertOk()
        ->assertJsonStructure(['data' => [['id', 'slug', 'title']]]);
});

it('POST /organizer/events → 201', function (): void {
    Sanctum::actingAs($this->orgUser);

    $this->postJson('/api/v1/organizer/events', [
        'title' => 'Mon nouveau festival',
        'description' => 'Festival de test.',
        'category' => 'festival',
        'city' => 'Bordeaux',
        'venue_name' => 'Hall A',
        'status' => 'draft',
    ])->assertCreated()
        ->assertJsonPath('data.title', 'Mon nouveau festival');
});

it('GET /organizer/events/{id} → 200 si owner, 403 sinon', function (): void {
    Sanctum::actingAs($this->orgUser);
    $this->getJson("/api/v1/organizer/events/{$this->event->id}")->assertOk();

    $otherUser = User::factory()->organizer()->create();
    Organizer::factory()->create(['user_id' => $otherUser->id]);
    Sanctum::actingAs($otherUser);
    $this->getJson("/api/v1/organizer/events/{$this->event->id}")->assertForbidden();
});

it('PATCH /organizer/events/{id} → 200', function (): void {
    Sanctum::actingAs($this->orgUser);

    $this->patchJson("/api/v1/organizer/events/{$this->event->id}", [
        'title' => 'Titre modifié',
    ])->assertOk()
        ->assertJsonPath('data.title', 'Titre modifié');
});

it('DELETE /organizer/events/{id} → 204 et marque archived', function (): void {
    Sanctum::actingAs($this->orgUser);

    $this->deleteJson("/api/v1/organizer/events/{$this->event->id}")
        ->assertNoContent();

    expect($this->event->fresh()->status)->toBe(Event::STATUS_ARCHIVED);
});

// ---------- Media ---------------------------------------------------------

it('POST /organizer/events/{id}/media → 201 + upload MinIO', function (): void {
    Sanctum::actingAs($this->orgUser);

    $file = UploadedFile::fake()->image('cover.jpg', 1920, 1080);

    $response = $this->postJson(
        "/api/v1/organizer/events/{$this->event->id}/media",
        ['file' => $file, 'alt_text' => 'Cover'],
    );

    $response->assertCreated()
        ->assertJsonStructure(['data' => ['id', 'path', 'url', 'mime_type']]);

    Storage::disk('s3')->assertExists($response->json('data.path'));
});

it('DELETE /organizer/events/{id}/media/{media} → 204', function (): void {
    Sanctum::actingAs($this->orgUser);

    Storage::disk('s3')->put('events/1/test.jpg', 'fake');
    $media = Media::factory()->create([
        'mediable_type' => Event::class,
        'mediable_id' => $this->event->id,
        'path' => 'events/1/test.jpg',
    ]);

    $this->deleteJson("/api/v1/organizer/events/{$this->event->id}/media/{$media->id}")
        ->assertNoContent();

    $this->assertDatabaseMissing('media', ['id' => $media->id]);
});

// ---------- Participants --------------------------------------------------

it('GET /organizer/events/{id}/participants → 200', function (): void {
    Sanctum::actingAs($this->orgUser);

    $this->getJson("/api/v1/organizer/events/{$this->event->id}/participants")
        ->assertOk()
        ->assertJsonStructure([
            'data',
        ]);
});
