<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Event;
use App\Models\EventSession;
use App\Models\Media;
use App\Models\Order;
use App\Models\Organizer;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\User;
use App\Services\Seeding\PlaceholderMediaService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Dataset de développement (~50 événements, ~200 tickets) — cf. §7.
 *
 * Implémentation factory-based : volume modeste, simplicité prime sur perf.
 * L'optimisation batch DB::table()->insert() est appliquée au realistic
 * (cf. RealisticDatasetSeeder).
 */
class LightDatasetSeeder extends Seeder
{
    public function run(PlaceholderMediaService $mediaService): void
    {
        // Reproductibilité : Faker seedé.
        fake()->seed(2026_05_07);

        $this->command?->info('Pool d\'images placeholders…');
        $mediaService->ensurePool();

        $this->command?->info('Visiteurs (45) + organisateurs (5)…');
        // Comptes démo documentés dans le README — créés en premier pour qu'ils
        // existent toujours, indépendamment des aléas Faker.
        $visitorDemo = User::factory()->create([
            'name' => 'Visitor Démo',
            'email' => 'visitor@demo.test',
        ]);
        $organizerDemoUser = User::factory()->organizer()->create([
            'name' => 'Organizer Démo',
            'email' => 'organizer@demo.test',
        ]);

        $visitors = User::factory()->count(44)->create()->prepend($visitorDemo);
        $organizerUsers = User::factory()->organizer()->count(4)->create()
            ->prepend($organizerDemoUser);

        $organizers = $organizerUsers->map(
            fn (User $u) => Organizer::factory()->create(['user_id' => $u->id])
        );

        // L'organizer démo est le premier de la liste : ses 10 events sortiront
        // naturellement de la première itération round-robin ci-dessous (cible
        // « ~3-5 events » respectée par le sous-ensemble visible côté UI).

        $this->command?->info('Événements (50) + médias…');
        $events = collect();
        foreach ($organizers as $organizer) {
            $orgEvents = Event::factory()->count(10)->create([
                'organizer_id' => $organizer->id,
            ]);
            $events = $events->concat($orgEvents);
        }

        // ~8 médias par événement → ~400 medias
        foreach ($events as $event) {
            $position = 0;
            for ($i = 0; $i < 8; $i++) {
                $entry = $mediaService->randomPoolEntry();
                Media::factory()->create([
                    'mediable_type' => Event::class,
                    'mediable_id' => $event->id,
                    'path' => $entry->path,
                    'mime_type' => $entry->mimeType,
                    'width' => $entry->width,
                    'height' => $entry->height,
                    'position' => $position++,
                ]);
            }
            // Cover = première image du pool tirée pour cet event.
            $cover = $mediaService->randomPoolEntry();
            $event->update(['cover_image_path' => $cover->path]);
        }

        $this->command?->info('Sessions (~80) + catégories (~240)…');
        $sessions = collect();
        foreach ($events as $event) {
            // 1 à 3 sessions par event → ~80 au total
            $count = fake()->numberBetween(1, 3);
            $eventSessions = EventSession::factory()->count($count)->create([
                'event_id' => $event->id,
            ]);
            $sessions = $sessions->concat($eventSessions);

            foreach ($eventSessions as $session) {
                // 3 catégories par session → ~240
                TicketCategory::factory()->count(3)->create([
                    'event_session_id' => $session->id,
                ]);
            }
        }

        $this->command?->info('Commandes (80) + tickets (200)…');
        // Garantit ≥ 3 orders pour le visitor démo (cf. README "Comptes de test").
        for ($i = 0; $i < 80; $i++) {
            $user = $i < 3 ? $visitorDemo : $visitors->random();
            $order = Order::factory()->create(['user_id' => $user->id]);

            $session = $sessions->random();
            $categories = TicketCategory::where('event_session_id', $session->id)->get();
            $category = $categories->random();

            $ticketCount = fake()->numberBetween(1, 4);
            for ($j = 0; $j < $ticketCount; $j++) {
                Ticket::factory()->create([
                    'order_id' => $order->id,
                    'ticket_category_id' => $category->id,
                    'event_session_id' => $session->id,
                    'holder_name' => fake()->name(),
                    'code' => Str::uuid()->toString(),
                ]);
            }

            // Compteur dénormalisé.
            $category->increment('sold', $ticketCount);
        }

        $this->command?->info('Favoris (100)…');
        // Pivot via DB direct (pas de Favorite model).
        $rows = [];
        $seen = [];
        while (count($rows) < 100) {
            $userId = $visitors->random()->id;
            $eventId = $events->random()->id;
            $key = "{$userId}-{$eventId}";
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $rows[] = [
                'user_id' => $userId,
                'event_id' => $eventId,
                'created_at' => now(),
            ];
        }
        DB::table('favorites')->insert($rows);

        $this->command?->info('Light dataset seedé.');
    }
}
