// =============================================================================
// k6 — checkout-stress.js
// =============================================================================
// Pic de réservations concurrentes sur la même catégorie de billets pour
// l'event « star » du seed réaliste. C'est ce scénario qui :
//
//   1. expose la concurrence offerte par PHP-FPM sous Nginx (Phase 4-bis :
//      pool dynamic 4-20 workers, pas d'OPcache),
//   2. saturera le tunnel synchrone (usleep 800-1500 ms + dompdf inline +
//      SMTP synchrone Mailpit, cf. @perf-debt OrderController),
//   3. peut révéler la race condition sur `ticket_categories.sold` faute
//      de SELECT FOR UPDATE SKIP LOCKED (sold dépassant quota).
//
// Sera la cible-référence du commit J3 « solution/j3-laravel » qui :
//   - bascule QUEUE_CONNECTION=redis + Horizon (PDF + mail en jobs)
//   - introduit le verrou ligne SKIP LOCKED dans la transaction
//   - active Octane + FrankenPHP (multi-process)
//
// Paramètres : ramp 30s → 20 VUs, plateau 60s, ramp-down 15s ≈ 2 min.
// =============================================================================

import http from 'k6/http';
import { check, sleep } from 'k6';

// Phase 4-bis : tout passe par Nginx (cf. homepage-load.js / search-load.js).
// `make k6` injecte `RESONANCE_BASE_URL=http://nginx`.
const API_URL = __ENV.RESONANCE_BASE_URL || 'http://localhost:8080';
const STAR_SLUG = __ENV.STAR_SLUG;

if (!STAR_SLUG) {
  throw new Error(
    '[checkout-stress] STAR_SLUG env var requise. Lance via `make k6`.'
  );
}

export const options = {
  scenarios: {
    checkout: {
      executor: 'ramping-vus',
      startVUs: 0,
      stages: [
        { duration: '30s', target: 20 },
        { duration: '60s', target: 20 },
        { duration: '15s', target: 0 },
      ],
      gracefulRampDown: '15s',
    },
  },
  thresholds: {
    // Pas de seuil de durée : on attend du starter qu'il soit lent
    // (tunnel à ~1.4 s par order, dominé par usleep paiement + dompdf
    // + SMTP synchrone).
    //
    // En starter prod-like (FPM multi-worker), 20 VUs sur la MÊME
    // ticket_category épuisent le quota en quelques secondes → la
    // majorité des requêtes finit en 422 (stock épuisé). Ce n'est pas
    // un bug : c'est la conséquence attendue de l'absence de file
    // d'attente côté checkout, résolu en branche solution/j3-laravel
    // (queue Redis + Horizon + verrou ligne SKIP LOCKED).
    //
    // Le seuil reste un sanity check pour détecter une vraie panne
    // (ex. 500 systématiques) plutôt qu'une garantie de succès du
    // tunnel. Tolérance large à 95 % pour absorber l'épuisement quota.
    http_req_failed: ['rate<0.95'],
  },
};

// =============================================================================
// setup() — login + récupération d'une cible (event_session_id, category_id)
// =============================================================================
export function setup() {
  // 1) Login visitor démo.
  const loginRes = http.post(
    `${API_URL}/api/v1/auth/login`,
    JSON.stringify({ email: 'visitor@demo.test', password: 'password' }),
    { headers: { 'Content-Type': 'application/json', Accept: 'application/json' } }
  );

  if (loginRes.status !== 200) {
    throw new Error(
      `[checkout-stress] login a échoué (status ${loginRes.status}). ` +
        'Vérifie que le seeder a bien provisionné visitor@demo.test (cf. resonance-spec.md §7).'
    );
  }
  const token = loginRes.json('data.token');
  if (!token) {
    throw new Error('[checkout-stress] token absent dans la réponse de login.');
  }

  // 2) Récupère la première session + première catégorie de l'event star.
  const sessionsRes = http.get(`${API_URL}/api/v1/events/${STAR_SLUG}/sessions`, {
    headers: { Accept: 'application/json' },
  });
  if (sessionsRes.status !== 200) {
    throw new Error(
      `[checkout-stress] /events/${STAR_SLUG}/sessions a échoué (status ${sessionsRes.status}).`
    );
  }
  const sessions = sessionsRes.json('data') || [];
  if (sessions.length === 0) {
    throw new Error(`[checkout-stress] aucune session pour ${STAR_SLUG}.`);
  }
  const session = sessions[0];
  const cats = session.ticket_categories || [];
  if (cats.length === 0) {
    throw new Error(
      `[checkout-stress] aucune ticket_category pour la session ${session.id}.`
    );
  }
  const cat = cats[0];

  // eslint-disable-next-line no-console
  console.log(
    `[checkout-stress] cible : event_session_id=${session.id}, ticket_category_id=${cat.id} ` +
      `(${cat.name}, sold=${cat.sold}/quota=${cat.quota})`
  );

  return {
    token,
    sessionId: session.id,
    categoryId: cat.id,
  };
}

// =============================================================================
// default() — POST /api/v1/orders concurrent
// =============================================================================
export default function (data) {
  const payload = JSON.stringify({
    event_session_id: data.sessionId,
    items: [
      {
        ticket_category_id: data.categoryId,
        quantity: 1,
        holder_name: `k6 VU${__VU} iter${__ITER}`,
      },
    ],
  });

  const res = http.post(`${API_URL}/api/v1/orders`, payload, {
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      Authorization: `Bearer ${data.token}`,
    },
    tags: { endpoint: 'orders' },
    timeout: '30s', // tunnel synchrone starter peut atteindre 5-10s sous charge
  });

  check(res, {
    'status 201 or 422 (stocks)': (r) => r.status === 201 || r.status === 422,
  });

  // Pas de sleep : on cherche la concurrence maximale par VU.
}
