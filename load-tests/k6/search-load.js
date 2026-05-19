// =============================================================================
// k6 — search-load.js
// =============================================================================
// Charge soutenue sur l'API recherche (`GET /api/v1/events?...`) avec
// différents jeux de filtres pour solliciter les colonnes non indexées
// du starter (`events.city`, `events.category`, `events.published_at`,
// recherche FTS via `ILIKE` sans index gin).
//
// Sujet pédagogique : coût SQL des prédicats non indexés, taille du
// payload sans pagination (1 200 events publiés sur le seed réaliste).
//
// Paramètres : 30 VUs, ramp 30s + plateau 90s + ramp-down 30s ≈ 2.5 min.
// =============================================================================

import http from 'k6/http';
import { check, sleep } from 'k6';
import { SharedArray } from 'k6/data';

// Phase 4-bis : l'API passe par Nginx. `make k6` injecte
// `RESONANCE_BASE_URL=http://nginx` (DNS interne, port 80 du container).
// En standalone, fallback sur `:8080` (défaut repo NGINX_PORT).
const API_URL = __ENV.RESONANCE_BASE_URL || 'http://localhost:8080';

// Plusieurs requêtes représentatives du parcours stagiaire.
const SEARCHES = new SharedArray('searches', () => [
  { qs: '', label: 'all-published' },
  { qs: 'category=concert', label: 'cat-concert' },
  { qs: 'category=festival', label: 'cat-festival' },
  { qs: 'city=Paris', label: 'city-paris' },
  { qs: 'city=Lyon', label: 'city-lyon' },
  { qs: 'city=Marseille&category=concert', label: 'city-cat-mix' },
  { qs: 'q=jazz', label: 'fulltext-jazz' },
  { qs: 'q=festival', label: 'fulltext-festival' },
]);

export const options = {
  scenarios: {
    search: {
      executor: 'ramping-vus',
      startVUs: 0,
      stages: [
        { duration: '30s', target: 30 },
        { duration: '90s', target: 30 },
        { duration: '30s', target: 0 },
      ],
      gracefulRampDown: '10s',
    },
  },
  thresholds: {
    http_req_failed: ['rate<0.05'],
  },
};

export default function () {
  const search = SEARCHES[Math.floor(Math.random() * SEARCHES.length)];
  const url = search.qs
    ? `${API_URL}/api/v1/events?${search.qs}`
    : `${API_URL}/api/v1/events`;

  const res = http.get(url, {
    headers: { Accept: 'application/json' },
    tags: { endpoint: 'events-search', filter: search.label },
  });

  check(res, {
    'status 200': (r) => r.status === 200,
    'has data array': (r) => {
      try {
        const body = r.json();
        return Array.isArray(body && body.data);
      } catch {
        return false;
      }
    },
  });

  // Pause utilisateur réaliste entre 2 recherches.
  sleep(2);
}
