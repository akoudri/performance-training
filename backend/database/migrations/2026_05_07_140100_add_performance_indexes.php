<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Index secondaires pour les hot paths Resonance (solution/j3-postgres).
 *
 * - 8 index B-tree (dont 3 partiels) sur events / event_sessions / tickets
 *   / orders / media couvrant les requêtes liste publique, fiche, stats
 *   organizer et listing participants.
 * - 1 index GIN sur to_tsvector('french', title || ' ' || description) pour
 *   la recherche full-text events (résout l'ILIKE non indexé du starter).
 *
 * @perf-fix: index secondaires Postgres — résout le @perf-debt §8 starter
 *            "aucun index secondaire" (cf. resonance-spec.md §9).
 *
 * Trade-off CREATE INDEX classique vs CONCURRENTLY :
 *   Cette migration utilise CREATE INDEX bloquant (lock ACCESS EXCLUSIVE
 *   court ; ~ quelques secondes sur tickets 200 k). En production sur une
 *   base live, CREATE INDEX CONCURRENTLY serait préférable (lock minimal
 *   ROW EXCLUSIVE qui n'empêche pas les SELECT/INSERT/UPDATE), mais
 *   CONCURRENTLY exige de tourner HORS transaction — incompatible avec
 *   le wrapping transactionnel par défaut des migrations Laravel.
 *   Cf. fiche atelier docs/ateliers/j3-postgres.md §"Notes production".
 */
return new class extends Migration
{
    public function up(): void
    {
        // -- events -----------------------------------------------------------
        // Index partiel sur les events publiés, ordonnés par published_at DESC :
        // le hot path liste publique (/api/v1/events sans filtre).
        DB::statement(<<<'SQL'
            CREATE INDEX idx_events_status_published
                ON events (status, published_at DESC)
                WHERE status = 'published'
            SQL);

        // Filtre par ville (sidebar /events).
        DB::statement('CREATE INDEX idx_events_city ON events (city)');

        // Filtre par catégorie + tri publication (sidebar /events).
        DB::statement(<<<'SQL'
            CREATE INDEX idx_events_category_published
                ON events (category, published_at DESC)
            SQL);

        // GIN tsvector français sur title || ' ' || description pour la
        // recherche plein-texte (résout l'ILIKE non indexé du starter).
        // L'expression doit être identique à celle des requêtes pour que
        // le planner choisisse l'index (cf. EventController@index).
        DB::statement(<<<'SQL'
            CREATE INDEX idx_events_search
                ON events USING gin (to_tsvector('french', title || ' ' || description))
            SQL);

        // -- event_sessions ---------------------------------------------------
        // Filtre + tri pour /events/{slug}/sessions et joins participants/stats.
        DB::statement(<<<'SQL'
            CREATE INDEX idx_sessions_event
                ON event_sessions (event_id, starts_at)
            SQL);

        // -- tickets ----------------------------------------------------------
        // Listing participants : WHERE event_session_id ORDER BY created_at DESC.
        // Cet index couvre directement les 5 000-7 000 tickets d'un event star.
        DB::statement(<<<'SQL'
            CREATE INDEX idx_tickets_session_created
                ON tickets (event_session_id, created_at DESC)
            SQL);

        // FK lookup tickets→orders (joins stats organizer + checkout).
        DB::statement('CREATE INDEX idx_tickets_order ON tickets (order_id)');

        // -- orders -----------------------------------------------------------
        // Hot path /me/tickets et historique paiements visiteur (partiel paid).
        DB::statement(<<<'SQL'
            CREATE INDEX idx_orders_user_paid
                ON orders (user_id, paid_at DESC)
                WHERE status = 'paid'
            SQL);

        // Stats organizer revenus 30j (partiel paid → tri/range sur paid_at).
        DB::statement(<<<'SQL'
            CREATE INDEX idx_orders_paid_at
                ON orders (paid_at)
                WHERE status = 'paid'
            SQL);

        // -- media (polymorphique) -------------------------------------------
        // Index composite (mediable_type, mediable_id, position) pour la
        // résolution polymorphique avec ordre stable côté API Resources.
        DB::statement(<<<'SQL'
            CREATE INDEX idx_media_mediable
                ON media (mediable_type, mediable_id, position)
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_events_status_published');
        DB::statement('DROP INDEX IF EXISTS idx_events_city');
        DB::statement('DROP INDEX IF EXISTS idx_events_category_published');
        DB::statement('DROP INDEX IF EXISTS idx_events_search');
        DB::statement('DROP INDEX IF EXISTS idx_sessions_event');
        DB::statement('DROP INDEX IF EXISTS idx_tickets_session_created');
        DB::statement('DROP INDEX IF EXISTS idx_tickets_order');
        DB::statement('DROP INDEX IF EXISTS idx_orders_user_paid');
        DB::statement('DROP INDEX IF EXISTS idx_orders_paid_at');
        DB::statement('DROP INDEX IF EXISTS idx_media_mediable');
    }
};
