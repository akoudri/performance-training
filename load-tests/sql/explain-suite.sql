\set ON_ERROR_STOP on
\timing on
\pset format aligned
\pset border 1

\echo '=========================================================================='
\echo 'Resonance — EXPLAIN suite (solution/j3-postgres)'
\echo ''
\echo 'Cinq requêtes représentatives encadrées par EXPLAIN (ANALYZE, BUFFERS).'
\echo 'Comparaison BEFORE (sur main, sans index) vs AFTER (sur cette branche,'
\echo 'index secondaires + GIN tsvector + postgresql.conf tuné + PgBouncer).'
\echo '=========================================================================='
\echo ''

--
-- Résolution dynamique des IDs représentatifs (event star + organizer démo).
-- L organizer démo (organizer@demo.test) possède l event star sur le seed
-- réaliste : c est lui qui rend les requêtes participants/stats démonstratives.
-- Si le seed change, ces requêtes restent justes (résolution par email +
-- comptage de tickets, pas d ID hardcodé).
--

SELECT id AS demo_org_id
FROM organizers o
WHERE o.user_id = (
  SELECT id FROM users WHERE email = 'organizer@demo.test' LIMIT 1
)
LIMIT 1
\gset

SELECT e.id AS star_event_id
FROM events e
WHERE e.organizer_id = :demo_org_id
ORDER BY (
  SELECT COUNT(*) FROM tickets t
  JOIN event_sessions es ON es.id = t.event_session_id
  WHERE es.event_id = e.id
) DESC
LIMIT 1
\gset

\echo ''
\echo '------------------------------------------------------------'
\echo 'Contexte résolu :'
\echo '  demo_org_id     = ' :demo_org_id
\echo '  star_event_id   = ' :star_event_id
\echo '------------------------------------------------------------'
\echo ''

\echo ''
\echo '=========================================================================='
\echo 'a. Recherche full-text events (q="concert paris")'
\echo '   → cible : Seq Scan → Bitmap Index Scan via idx_events_search (GIN)'
\echo '=========================================================================='
EXPLAIN (ANALYZE, BUFFERS, FORMAT TEXT)
SELECT id, title FROM events
WHERE to_tsvector('french', title || ' ' || description)
      @@ plainto_tsquery('french', 'concert paris')
AND status = 'published'
ORDER BY published_at DESC
LIMIT 20;

\echo ''
\echo '=========================================================================='
\echo 'b. Listing participants — event star (top 100 derniers tickets)'
\echo '   → cible : Parallel Seq Scan tickets → Index Scan idx_tickets_session_created'
\echo '=========================================================================='
EXPLAIN (ANALYZE, BUFFERS, FORMAT TEXT)
SELECT t.code, t.holder_name, t.status, tc.name
FROM tickets t
JOIN ticket_categories tc ON tc.id = t.ticket_category_id
WHERE t.event_session_id IN (
  SELECT id FROM event_sessions WHERE event_id = :star_event_id
)
ORDER BY t.created_at DESC
LIMIT 100;

\echo ''
\echo '=========================================================================='
\echo 'c. Stats organizer — revenus 30 derniers jours'
\echo '   → cible : multiples Seq Scans → cascade d Index Scans'
\echo '   (idx_orders_paid_at partiel + idx_tickets_order + idx_sessions_event)'
\echo '=========================================================================='
EXPLAIN (ANALYZE, BUFFERS, FORMAT TEXT)
SELECT DATE(o.paid_at) AS day, COUNT(*) AS orders, SUM(o.total_cents) AS revenue
FROM orders o
JOIN tickets t ON t.order_id = o.id
JOIN event_sessions es ON es.id = t.event_session_id
JOIN events e ON e.id = es.event_id
WHERE e.organizer_id = :demo_org_id
AND o.status = 'paid'
AND o.paid_at >= NOW() - INTERVAL '30 days'
GROUP BY DATE(o.paid_at)
ORDER BY day DESC;

\echo ''
\echo '=========================================================================='
\echo 'd. Recherche events par ville et catégorie (Paris / concert)'
\echo '   → cible : Seq Scan → Index Scan idx_events_city + filter category'
\echo '=========================================================================='
EXPLAIN (ANALYZE, BUFFERS, FORMAT TEXT)
SELECT id, title, city FROM events
WHERE city = 'Paris' AND category = 'concert'
AND status = 'published'
ORDER BY published_at DESC
LIMIT 20;

\echo ''
\echo '=========================================================================='
\echo 'e. Comptage tickets vendus par event (analytics organizer)'
\echo '   → cible : Parallel Seq Scan tickets → Index Scan idx_tickets_session_created'
\echo '=========================================================================='
EXPLAIN (ANALYZE, BUFFERS, FORMAT TEXT)
SELECT e.id, e.title, COUNT(t.id) AS tickets_sold
FROM events e
LEFT JOIN event_sessions es ON es.event_id = e.id
LEFT JOIN tickets t ON t.event_session_id = es.id AND t.status = 'valid'
WHERE e.organizer_id = :demo_org_id
GROUP BY e.id
ORDER BY tickets_sold DESC
LIMIT 20;

\echo ''
\echo '=========================================================================='
\echo 'Fin de la suite EXPLAIN.'
\echo '=========================================================================='
