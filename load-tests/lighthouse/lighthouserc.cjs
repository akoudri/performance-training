// =============================================================================
// Lighthouse CI — Resonance (starter)
// =============================================================================
// Audit perf des écrans publics critiques. Mode Mobile / Slow 4G / 4× CPU
// throttling, 3 runs par URL, médiane prise en compte.
//
// Usage habituel :
//   make lighthouse                  # résout STAR_SLUG via la base et lance l'audit
//
// Usage manuel (rare) :
//   STAR_SLUG=excepturi-amet-vero-600 \
//   LHCI_OUTPUT_DIR="$PWD/docs/benchmarks/lighthouse-latest" \
//     npx lhci autorun --config=load-tests/lighthouse/lighthouserc.cjs
//
// Pages auth-required (non couvertes ici, voir docs/benchmarks/README.md) :
//   - /organizer/dashboard
//   - /organizer/events/{id}/participants  (event star ≥ 5 000 lignes)
// L'auth Resonance utilise un Bearer Sanctum stocké en localStorage côté
// frontend (cf. mémoire feedback_nuxt_ssr_auth) — Lighthouse CI ne peut pas
// y accéder en simple `collect.url`. Pour automatiser plus tard, voir :
//   https://github.com/GoogleChrome/lighthouse-ci/blob/main/docs/configuration.md#authentication
// (TODO: ajouter `puppeteerScript` qui POST /api/v1/auth/login puis
//        page.evaluate(token => localStorage.setItem('auth_token', token)))
// =============================================================================

'use strict';

const STAR_SLUG = process.env.STAR_SLUG;
if (!STAR_SLUG) {
  // eslint-disable-next-line no-console
  console.error(
    '[lighthouserc] STAR_SLUG manquant. Lance via `make lighthouse` ' +
      '(qui le résout depuis la base) ou exporte-le manuellement.'
  );
  process.exit(1);
}

// Phase 4-bis : la cible par défaut est le frontal Nginx (et NON plus Nuxt
// direct :3000). C'est ce parcours qui correspond à l'expérience utilisateur
// réelle (Nginx → Nuxt SSR → API).
//
// L'URL est lue depuis `RESONANCE_BASE_URL` (var injectée par `make
// lighthouse` à partir de NGINX_PORT du `.env`). Override manuel possible :
// `RESONANCE_BASE_URL=http://localhost:9090 make lighthouse`. Le fallback
// `:8080` correspond au défaut repo (`NGINX_PORT=8080` dans `.env.example`).
const BASE_URL = process.env.RESONANCE_BASE_URL || 'http://localhost:8080';
const OUTPUT_DIR = process.env.LHCI_OUTPUT_DIR || './reports';

module.exports = {
  ci: {
    collect: {
      url: [
        `${BASE_URL}/`,
        `${BASE_URL}/events/${STAR_SLUG}`,
      ],
      numberOfRuns: 3,
      settings: {
        // Mode Mobile + Slow 4G + 4× CPU throttling. Lighthouse n'a pas de
        // preset 'mobile' (les presets disponibles sont perf/experimental/
        // desktop) — c'est le défaut, mais on l'explicite ici pour la
        // reproductibilité et pour exposer les paramètres aux apprenants.
        formFactor: 'mobile',
        screenEmulation: {
          mobile: true,
          width: 412,
          height: 823,
          deviceScaleFactor: 1.75,
          disabled: false,
        },
        throttling: {
          // Profil Slow 4G (cf. lighthouse/core/config/constants.js).
          rttMs: 150,
          throughputKbps: 1638.4,
          requestLatencyMs: 562.5,
          downloadThroughputKbps: 1474.56,
          uploadThroughputKbps: 675,
          cpuSlowdownMultiplier: 4,
        },
        // --no-sandbox : nécessaire sur certains hôtes Linux où le sandbox
        // Chrome n'est pas autorisé (containers, CI, snap chromium).
        chromeFlags: '--no-sandbox',
      },
    },
    // Aucune assertion en starter — on observe et on baseline. Les branches
    // `solution/jX-name` ajouteront des assertions ciblées (par ex.
    // categories.performance.minScore ≥ 0.6 sur solution/j1-cdn-cache),
    // sous la clé `assert: { assertions: { ... } }`.
    upload: {
      target: 'filesystem',
      outputDir: OUTPUT_DIR,
      reportFilenamePattern: '%%PATHNAME%%-%%DATETIME%%-report.%%EXTENSION%%',
    },
  },
};
