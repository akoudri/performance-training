<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Event;
use App\Models\EventSession;
use App\Models\Media;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Seeding\PlaceholderMediaService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Dataset "réaliste" (cf. resonance-spec.md §7) — version 1 (batched).
 *
 * Volumes :
 *   - users 8000 (7980 visiteurs + 20 organisateurs)
 *   - organizers 20
 *   - events 1500 (1200 published + 300 draft/archived)
 *   - event_sessions 2500
 *   - ticket_categories 7500
 *   - media 12000
 *   - orders 120 000
 *   - tickets 200 000
 *   - favorites 40 000
 *
 * Stratégie :
 *   - DB::table()->insert() par batch de 1000 (cf. spec §7).
 *   - IDs pré-alloués (séquentiels), `setval` en fin de table.
 *   - Single transaction globale.
 *   - Faker seedé pour reproductibilité.
 *   - Aucun observer / model event (insert direct).
 *
 * Cible perf §11 : < 10 min. La v1 atteint ~18s sur Linux x86_64 / NVMe
 * / Docker Desktop équivalent (cf. commit étape 8). L'écart confortable
 * permet d'ajouter de la richesse au dataset sans dépasser la cible.
 *
 * Leviers d'optimisation envisagés mais non nécessaires actuellement :
 *   - COPY binaire (psql) au lieu d'INSERT batched
 *   - SET session_replication_role = replica (skip triggers FK)
 *   - Génération UUID en PG via gen_random_uuid()
 *   - Insertion en parallèle (forks)
 */
class RealisticDatasetSeeder extends Seeder
{
    private const BATCH = 1000;

    private const N_USERS = 8000;

    private const N_ORGANIZER_USERS = 20;

    private const N_VISITORS = self::N_USERS - self::N_ORGANIZER_USERS;

    private const N_ORGANIZERS = 20;

    private const N_EVENTS = 1500;

    private const N_PUBLISHED_EVENTS = 1200;

    private const N_SESSIONS = 2500;

    private const N_CATEGORIES = 7500;

    private const N_MEDIA = 12000;

    private const N_ORDERS = 120_000;

    private const N_TICKETS = 200_000;

    private const N_FAVORITES = 40_000;

    private const FR_CITIES = [
        'Paris', 'Lyon', 'Marseille', 'Bordeaux', 'Lille', 'Nantes', 'Toulouse',
        'Strasbourg', 'Nice', 'Montpellier', 'Rennes', 'Reims', 'Le Havre',
        'Saint-Étienne', 'Toulon', 'Grenoble', 'Dijon', 'Angers', 'Nîmes',
        'Villeurbanne', 'Saint-Denis', 'Aix-en-Provence', 'Brest', 'Limoges',
        'Tours', 'Amiens', 'Perpignan', 'Metz', 'Besançon', 'Orléans',
    ];

    /**
     * Events « stars » avec une forte concentration de tickets, pour rendre
     * la virtualisation /organizer/events/{id}/participants démonstrative
     * en J2 (sinon le DOM 5000+ promis par §5 écran 6 ne se manifeste pas).
     *
     * - event_id=600 → organizer_id=((600-1)%20)+1 = 20 = organizer démo.
     * - 7 autres répartis sur des organizers distincts (orgs 13/19/11/12/15/2/18).
     * - Total stars ≈ 50 000 tickets (sur 200 000), reste réparti uniformément.
     * - Chaque star est forcé en catégorie festival/concert (cf. seedEvents).
     */
    private const STAR_EVENT_TICKETS = [
        600 => 7000,    // organizer démo (organizer_id=20)
        73 => 5000,     // org 13
        159 => 6000,    // org 19
        271 => 7500,    // org 11
        412 => 8000,    // org 12
        555 => 4500,    // org 15
        842 => 6500,    // org 2
        1098 => 5500,   // org 18
    ];

    public function run(PlaceholderMediaService $mediaService): void
    {
        fake()->seed(2026_05_07);
        $start = microtime(true);

        $this->command?->info('Pool d\'images placeholders…');
        $pool = $mediaService->ensurePool();
        $poolSize = count($pool);

        $now = now();
        $passwordHash = Hash::make('password');

        DB::transaction(function () use ($pool, $poolSize, $now, $passwordHash) {
            $this->seedUsers($now, $passwordHash);
            $this->seedOrganizers($now);
            $this->seedEvents($now, $pool, $poolSize);
            $this->seedSessions($now);
            $this->seedTicketCategories($now);
            $this->seedMedia($now, $pool, $poolSize);
            $this->seedOrders($now);
            $this->seedTickets($now);
            $this->seedFavorites($now);
            $this->updateTicketCategorySoldCounters();
            $this->bumpSequences();
        });

        $duration = round(microtime(true) - $start, 1);
        $this->command?->info("Realistic dataset seedé en {$duration}s.");
    }

    private function seedUsers(\DateTimeInterface $now, string $passwordHash): void
    {
        $this->command?->info('users (8000)…');
        $bar = $this->command?->getOutput()?->createProgressBar(self::N_USERS);
        $bar?->start();

        // Comptes démo : on remplace les deux derniers ids de chaque tranche
        // (id=N_VISITORS → visitor démo, id=N_USERS → organizer démo lié à
        // organizer_id=20 grâce au round-robin user_id = N_VISITORS + organizer_id).
        // Documenté dans le README section "Comptes de test".
        $batch = [];
        for ($id = 1; $id <= self::N_USERS; $id++) {
            $isOrganizer = $id > self::N_VISITORS;

            if ($id === self::N_VISITORS) {
                $email = 'visitor@demo.test';
                $name = 'Visitor Démo';
            } elseif ($id === self::N_USERS) {
                $email = 'organizer@demo.test';
                $name = 'Organizer Démo';
            } else {
                $email = sprintf('user-%05d@resonance.test', $id);
                $name = fake()->name();
            }

            $batch[] = [
                'id' => $id,
                'name' => $name,
                'email' => $email,
                'email_verified_at' => $now,
                'password' => $passwordHash,
                'role' => $isOrganizer ? 'organizer' : 'visitor',
                'remember_token' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            if (count($batch) === self::BATCH) {
                DB::table('users')->insert($batch);
                $bar?->advance(count($batch));
                $batch = [];
            }
        }
        if ($batch !== []) {
            DB::table('users')->insert($batch);
            $bar?->advance(count($batch));
        }
        $bar?->finish();
        $this->command?->newLine();
    }

    private function seedOrganizers(\DateTimeInterface $now): void
    {
        $this->command?->info('organizers (20)…');
        $rows = [];
        for ($id = 1; $id <= self::N_ORGANIZERS; $id++) {
            $userId = self::N_VISITORS + $id; // 7981..8000
            $companyName = fake()->company();
            $rows[] = [
                'id' => $id,
                'user_id' => $userId,
                'company_name' => $companyName,
                'slug' => Str::slug($companyName).'-'.$id,
                'description' => fake()->paragraph(3),
                'logo_path' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('organizers')->insert($rows);
    }

    private function seedEvents(\DateTimeInterface $now, array $pool, int $poolSize): void
    {
        $this->command?->info('events (1500)…');
        $bar = $this->command?->getOutput()?->createProgressBar(self::N_EVENTS);
        $bar?->start();

        $categories = Event::CATEGORIES;
        $batch = [];
        for ($id = 1; $id <= self::N_EVENTS; $id++) {
            $title = ucfirst(fake()->words(fake()->numberBetween(3, 6), asText: true));
            $isPublished = $id <= self::N_PUBLISHED_EVENTS;
            $isDraft = ! $isPublished && $id <= (self::N_PUBLISHED_EVENTS + 200);

            // Stars forcés en festival/concert (cohérent avec une forte
            // affluence). Alternance déterministe pour la reproductibilité.
            $category = isset(self::STAR_EVENT_TICKETS[$id])
                ? ($id % 2 === 0 ? 'concert' : 'festival')
                : $categories[array_rand($categories)];

            $batch[] = [
                'id' => $id,
                'organizer_id' => (($id - 1) % self::N_ORGANIZERS) + 1,
                'slug' => Str::slug($title).'-'.$id,
                'title' => $title,
                'description' => fake()->paragraphs(4, asText: true),
                'category' => $category,
                'city' => self::FR_CITIES[array_rand(self::FR_CITIES)],
                'country' => 'FR',
                'venue_name' => fake()->company().' Hall',
                'cover_image_path' => $pool[$id % $poolSize]->path,
                'published_at' => $isPublished ? $now : null,
                'status' => $isPublished
                    ? Event::STATUS_PUBLISHED
                    : ($isDraft ? Event::STATUS_DRAFT : Event::STATUS_ARCHIVED),
                'created_at' => $now,
                'updated_at' => $now,
            ];
            if (count($batch) === self::BATCH) {
                DB::table('events')->insert($batch);
                $bar?->advance(count($batch));
                $batch = [];
            }
        }
        if ($batch !== []) {
            DB::table('events')->insert($batch);
            $bar?->advance(count($batch));
        }
        $bar?->finish();
        $this->command?->newLine();
    }

    private function seedSessions(\DateTimeInterface $now): void
    {
        $this->command?->info('event_sessions (2500)…');
        $bar = $this->command?->getOutput()?->createProgressBar(self::N_SESSIONS);
        $bar?->start();

        $batch = [];
        for ($id = 1; $id <= self::N_SESSIONS; $id++) {
            // Distribution : event_id round-robin sur les 1500 events
            // → la majorité aura 1 session, certains en auront 2.
            $eventId = (($id - 1) % self::N_EVENTS) + 1;
            $startsAt = (clone $now)->modify('+'.fake()->numberBetween(1, 180).' days');
            $endsAt = (clone $startsAt)->modify('+3 hours');
            $batch[] = [
                'id' => $id,
                'event_id' => $eventId,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'doors_open_at' => (clone $startsAt)->modify('-30 minutes'),
                'status' => EventSession::STATUS_SCHEDULED,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            if (count($batch) === self::BATCH) {
                DB::table('event_sessions')->insert($batch);
                $bar?->advance(count($batch));
                $batch = [];
            }
        }
        if ($batch !== []) {
            DB::table('event_sessions')->insert($batch);
            $bar?->advance(count($batch));
        }
        $bar?->finish();
        $this->command?->newLine();
    }

    private function seedTicketCategories(\DateTimeInterface $now): void
    {
        $this->command?->info('ticket_categories (7500)…');
        $bar = $this->command?->getOutput()?->createProgressBar(self::N_CATEGORIES);
        $bar?->start();

        $names = ['Carré Or', 'Catégorie 1', 'Catégorie 2'];
        $prices = [25000, 8000, 4000];

        $batch = [];
        for ($id = 1; $id <= self::N_CATEGORIES; $id++) {
            $sessionId = (int) ceil($id / 3);
            $catIndex = ($id - 1) % 3;

            // Mapping session → event : seedSessions distribue en
            // round-robin event_id = ((sess-1) % N_EVENTS) + 1.
            $eventId = (($sessionId - 1) % self::N_EVENTS) + 1;
            $starTickets = self::STAR_EVENT_TICKETS[$eventId] ?? 0;

            if ($starTickets > 0) {
                // Surprovisionne ×1.5 par rapport au sold ciblé pour laisser
                // un remaining crédible. Nombre de catégories par event : 6
                // pour les events ≤1000 (2 sessions), 3 pour les events
                // 1001..1500 (1 session).
                $catCountForEvent = $eventId <= 1000 ? 6 : 3;
                $quotaCenter = (int) ($starTickets * 1.5 / $catCountForEvent);
                $quota = fake()->numberBetween(
                    max(500, $quotaCenter - 300),
                    $quotaCenter + 300,
                );
            } else {
                $quota = fake()->numberBetween(100, 500);
            }

            $batch[] = [
                'id' => $id,
                'event_session_id' => $sessionId,
                'name' => $names[$catIndex],
                'price_cents' => $prices[$catIndex],
                'quota' => $quota,
                'sold' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            if (count($batch) === self::BATCH) {
                DB::table('ticket_categories')->insert($batch);
                $bar?->advance(count($batch));
                $batch = [];
            }
        }
        if ($batch !== []) {
            DB::table('ticket_categories')->insert($batch);
            $bar?->advance(count($batch));
        }
        $bar?->finish();
        $this->command?->newLine();
    }

    private function seedMedia(\DateTimeInterface $now, array $pool, int $poolSize): void
    {
        $this->command?->info('media (12000)…');
        $bar = $this->command?->getOutput()?->createProgressBar(self::N_MEDIA);
        $bar?->start();

        $batch = [];
        for ($id = 1; $id <= self::N_MEDIA; $id++) {
            $eventId = (($id - 1) % self::N_EVENTS) + 1;
            $position = (int) (($id - 1) / self::N_EVENTS);
            $entry = $pool[$id % $poolSize];
            $batch[] = [
                'id' => $id,
                'mediable_type' => Event::class,
                'mediable_id' => $eventId,
                'type' => Media::TYPE_IMAGE,
                'path' => $entry->path,
                'mime_type' => $entry->mimeType,
                'width' => $entry->width,
                'height' => $entry->height,
                'duration_seconds' => null,
                'position' => $position,
                'alt_text' => 'Photo d\'illustration',
                'created_at' => $now,
                'updated_at' => $now,
            ];
            if (count($batch) === self::BATCH) {
                DB::table('media')->insert($batch);
                $bar?->advance(count($batch));
                $batch = [];
            }
        }
        if ($batch !== []) {
            DB::table('media')->insert($batch);
            $bar?->advance(count($batch));
        }
        $bar?->finish();
        $this->command?->newLine();
    }

    private function seedOrders(\DateTimeInterface $now): void
    {
        $this->command?->info('orders (120 000)…');
        $bar = $this->command?->getOutput()?->createProgressBar(self::N_ORDERS);
        $bar?->start();

        // Garantit ≥ 3 orders pour le compte visitor démo (user_id = N_VISITORS).
        // Les 4 premiers ids sont forcés ; au-delà, distribution aléatoire.
        $forcedDemoOrderIds = [1, 2, 3, 4];

        $batch = [];
        for ($id = 1; $id <= self::N_ORDERS; $id++) {
            $userId = in_array($id, $forcedDemoOrderIds, true)
                ? self::N_VISITORS
                : mt_rand(1, self::N_VISITORS);
            $batch[] = [
                'id' => $id,
                'user_id' => $userId,
                'total_cents' => mt_rand(2000, 50000),
                'status' => Order::STATUS_PAID,
                'paid_at' => $now,
                'payment_reference' => Str::uuid()->toString(),
                'created_at' => $now,
                'updated_at' => $now,
            ];
            if (count($batch) === self::BATCH) {
                DB::table('orders')->insert($batch);
                $bar?->advance(count($batch));
                $batch = [];
            }
        }
        if ($batch !== []) {
            DB::table('orders')->insert($batch);
            $bar?->advance(count($batch));
        }
        $bar?->finish();
        $this->command?->newLine();
    }

    private function seedTickets(\DateTimeInterface $now): void
    {
        $this->command?->info('tickets (200 000)…');
        $bar = $this->command?->getOutput()?->createProgressBar(self::N_TICKETS);
        $bar?->start();

        $firstNames = ['Jean', 'Marie', 'Pierre', 'Sophie', 'Luc', 'Camille', 'Antoine', 'Élise'];
        $lastNames = ['Martin', 'Dubois', 'Lefebvre', 'Moreau', 'Garcia', 'Bernard', 'Roux', 'Leroy'];

        // 1) Calcul du nombre de tickets par event.
        //    - Stars : valeurs explicites (cf. STAR_EVENT_TICKETS).
        //    - Non-stars : reste réparti uniformément, residual sur les
        //      premiers events pour atteindre exactement N_TICKETS.
        $totalStar = array_sum(self::STAR_EVENT_TICKETS);
        $nonStarTotal = self::N_TICKETS - $totalStar;
        $nonStarCount = self::N_EVENTS - count(self::STAR_EVENT_TICKETS);
        $base = intdiv($nonStarTotal, $nonStarCount);
        $residual = $nonStarTotal - $base * $nonStarCount;

        $targetPerEvent = [];
        $residualLeft = $residual;
        for ($eventId = 1; $eventId <= self::N_EVENTS; $eventId++) {
            if (isset(self::STAR_EVENT_TICKETS[$eventId])) {
                $targetPerEvent[$eventId] = self::STAR_EVENT_TICKETS[$eventId];
            } else {
                $extra = $residualLeft > 0 ? 1 : 0;
                $targetPerEvent[$eventId] = $base + $extra;
                if ($residualLeft > 0) {
                    $residualLeft--;
                }
            }
        }

        // 2) Génération en parcourant les events dans l'ordre.
        //    Sessions par event :
        //      - event_id ≤ 1000 : sessions [event_id, event_id + N_EVENTS]
        //      - event_id > 1000 : sessions [event_id]
        //    Catégories par session N : (N-1)*3 + 1, (N-1)*3 + 2, (N-1)*3 + 3.
        //    order_id reste en round-robin sur ticket_id (volumétrie 200k/120k
        //     conservée, ratio 1.67 tickets/order moyen).
        $ticketId = 1;
        $batch = [];
        for ($eventId = 1; $eventId <= self::N_EVENTS; $eventId++) {
            $count = $targetPerEvent[$eventId];
            if ($count === 0) {
                continue;
            }

            $sessions = $eventId <= 1000
                ? [$eventId, $eventId + self::N_EVENTS]
                : [$eventId];

            $catIds = [];
            foreach ($sessions as $sessId) {
                for ($k = 0; $k < 3; $k++) {
                    $catIds[] = ($sessId - 1) * 3 + $k + 1;
                }
            }
            $catCount = count($catIds);

            for ($i = 0; $i < $count; $i++) {
                $categoryId = $catIds[$i % $catCount];
                $sessionId = (int) ceil($categoryId / 3);
                $orderId = (($ticketId - 1) % self::N_ORDERS) + 1;

                $batch[] = [
                    'id' => $ticketId,
                    'order_id' => $orderId,
                    'ticket_category_id' => $categoryId,
                    'event_session_id' => $sessionId,
                    'code' => Str::uuid()->toString(),
                    'holder_name' => $firstNames[array_rand($firstNames)].' '.$lastNames[array_rand($lastNames)],
                    'status' => Ticket::STATUS_VALID,
                    'pdf_path' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $ticketId++;

                if (count($batch) === self::BATCH) {
                    DB::table('tickets')->insert($batch);
                    $bar?->advance(count($batch));
                    $batch = [];
                }
            }
        }
        if ($batch !== []) {
            DB::table('tickets')->insert($batch);
            $bar?->advance(count($batch));
        }
        $bar?->finish();
        $this->command?->newLine();
    }

    private function seedFavorites(\DateTimeInterface $now): void
    {
        $this->command?->info('favorites (40 000)…');
        $bar = $this->command?->getOutput()?->createProgressBar(self::N_FAVORITES);
        $bar?->start();

        // Garantit l'unicité (user_id, event_id) sans coût mémoire dramatique
        // : 40k entrées dans une hash-set PHP → ~10 Mo, acceptable.
        $seen = [];
        $batch = [];
        $count = 0;
        while ($count < self::N_FAVORITES) {
            $userId = mt_rand(1, self::N_VISITORS);
            $eventId = mt_rand(1, self::N_PUBLISHED_EVENTS); // favoris sur events publiés
            $key = $userId * 10_000 + $eventId;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $batch[] = [
                'user_id' => $userId,
                'event_id' => $eventId,
                'created_at' => $now,
            ];
            $count++;
            if (count($batch) === self::BATCH) {
                DB::table('favorites')->insert($batch);
                $bar?->advance(count($batch));
                $batch = [];
            }
        }
        if ($batch !== []) {
            DB::table('favorites')->insert($batch);
            $bar?->advance(count($batch));
        }
        $bar?->finish();
        $this->command?->newLine();
    }

    private function updateTicketCategorySoldCounters(): void
    {
        $this->command?->info('mise à jour ticket_categories.sold (cohérence)…');
        DB::statement(<<<'SQL'
            UPDATE ticket_categories tc
            SET sold = sub.sold_count
            FROM (
                SELECT ticket_category_id, COUNT(*)::int AS sold_count
                FROM tickets
                WHERE status = 'valid'
                GROUP BY ticket_category_id
            ) AS sub
            WHERE tc.id = sub.ticket_category_id
        SQL);
    }

    private function bumpSequences(): void
    {
        // Aligne les sequences postgres après inserts à id explicite.
        $this->command?->info('alignement des séquences…');
        foreach (['users', 'organizers', 'events', 'event_sessions',
            'ticket_categories', 'media', 'orders', 'tickets'] as $table) {
            DB::statement("SELECT setval('{$table}_id_seq', (SELECT MAX(id) FROM {$table}))");
        }
    }
}
