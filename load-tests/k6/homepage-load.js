// =============================================================================
// k6 — homepage-load.js
// =============================================================================
// Charge soutenue sur la home Nuxt SSR (`GET /`) servie via Nginx en
// frontal. Lancé via le container grafana/k6 sur le réseau Compose ; les
// VUs frappent `nginx:80` qui proxy_pass vers `frontend:3000` (Nuxt SSR
// Node preview), lequel fetche `nginx:80/api/v1/events` pour SSR.
//
// Phase 4-bis : la chaîne complète (Nginx + FPM + Nuxt prod) est mesurée,
// pas un dev server isolé.
//
// Sujet pédagogique : TTFB, payload SSR, coût de l'absence de
// `routeRules: { '/': { isr: 60 } }` (chaque requête recompile le HTML),
// absence de gzip/brotli côté Nginx (cf. @perf-debt config).
//
// Paramètres : 20 VUs, ramp 30s + plateau 60s + ramp-down 30s ≈ 2 min.
// =============================================================================

import http from 'k6/http';
import { check, sleep } from 'k6';

// L'URL du frontal Resonance est lue depuis `RESONANCE_BASE_URL`. `make k6`
// injecte `http://nginx` (DNS interne du réseau Compose). En standalone
// (`k6 run` direct), fallback sur `:8080` (défaut repo NGINX_PORT).
const BASE_URL = __ENV.RESONANCE_BASE_URL || 'http://localhost:8080';

export const options = {
  scenarios: {
    homepage: {
      executor: 'ramping-vus',
      startVUs: 0,
      stages: [
        { duration: '30s', target: 20 },  // ramp-up
        { duration: '60s', target: 20 },  // plateau
        { duration: '30s', target: 0 },   // ramp-down
      ],
      gracefulRampDown: '10s',
    },
  },
  thresholds: {
    // Pas d'assertion bloquante en starter — on observe et on baseline.
    // Les branches solution/jX-name pourront ajouter des seuils ciblés
    // (ex: 'http_req_duration{group:::homepage}': ['p(95)<800'] sur j1).
    http_req_failed: ['rate<0.05'], // < 5 % d'erreurs HTTP : sanity check
  },
};

export default function () {
  const res = http.get(`${BASE_URL}/`, {
    headers: { Accept: 'text/html' },
    tags: { endpoint: 'home' },
  });

  check(res, {
    'status 200': (r) => r.status === 200,
    'is HTML': (r) =>
      typeof r.headers['Content-Type'] === 'string' &&
      r.headers['Content-Type'].includes('text/html'),
  });

  // Pause utilisateur réaliste entre 2 vues.
  sleep(1);
}
