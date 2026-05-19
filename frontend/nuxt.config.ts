// https://nuxt.com/docs/api/configuration/nuxt-config
//
// Resonance — frontend (branche solution/j1-cdn-cache).
// Configuration toujours minimaliste sur le bundle (pas de @nuxt/image,
// pas de @nuxt/fonts — réservés à solution/j2-bundle), mais elle gagne
// désormais les `routeRules` ISR/SWR de J1.
//
// @perf-fix: routeRules ISR/SWR — solution/j1-cdn-cache (cf. ci-dessous).
// @perf-debt: pas de @nuxt/image — résolu en J2 atelier "j2-bundle".
// @perf-debt: pas de @nuxt/fonts — résolu en J2 atelier "j2-bundle".

export default defineNuxtConfig({
  compatibilityDate: '2025-07-15',
  devtools: { enabled: true },

  modules: [
    '@nuxtjs/tailwindcss',
    '@pinia/nuxt',
    '@nuxt/eslint',
  ],

  // ---- routeRules — cache applicatif Nitro (solution/j1-cdn-cache) -----
  // Nitro applique la règle la plus spécifique (`/events` exact gagne sur
  // `/events/**`).
  //
  // Mécanisme : `swr: <n>` (Stale-While-Revalidate) génère un
  // `cachedEventHandler` runtime → cache HTML rendu **en mémoire côté
  // serveur Node**, pas seulement `Cache-Control` envoyé au browser.
  // Conséquence : k6 (qui n'a pas de cache client) bénéficie aussi du
  // cache dès le 2ᵉ hit dans la fenêtre TTL — c'est ce qui débloque la
  // cible « k6 home p95 < 2 s ».
  //
  // Pourquoi pas `isr` ? Sur preset `node-server` (notre déploiement),
  // `isr` est un no-op runtime : Nitro ne génère un `cache:` block que
  // pour `swr`. `isr` est conçu pour les presets edge (Vercel,
  // Cloudflare Workers) où la plateforme gère le cache. Cf. note
  // technique de la fiche atelier `docs/ateliers/j1-cdn-cache.md`.
  //
  // Note `/api/**` : aucune `routeRule` ici. Les requêtes `/api/*` sont
  // servies par Laravel via Nginx FastCGI — elles n'atteignent **pas**
  // le serveur Nitro de Nuxt. Une `{ cors: true }` sur `/api/**` serait
  // un no-op fonctionnel. Le CORS éventuel se gère côté Laravel
  // (config/cors.php) ; le caching applicatif des réponses API est porté
  // par le middleware Laravel `SetEventsCacheControl` (Cache-Control HTTP).
  routeRules: {
    '/': { swr: 60 },
    '/events': { swr: 60 },
    '/events/**': { swr: 300 },
    '/organizer/**': { ssr: false },
  },

  typescript: {
    strict: true,
    typeCheck: false,
  },

  // URL de l'API Laravel — duale parce que Nuxt SSR tourne dans le réseau
  // Docker (`http://nginx/api/v1`) alors que le browser parle à l'host
  // (`http://localhost:${NGINX_PORT}/api/v1`). Le composable useApi choisit
  // la bonne URL via `import.meta.server`. En Phase 4-bis tout passe par
  // Nginx (le backend FPM n'écoute plus en HTTP direct).
  //
  // Les valeurs effectives sont injectées par `docker-compose.yml` depuis
  // les vars d'env. Les fallbacks ci-dessous correspondent au défaut repo
  // (`NGINX_PORT=8080`).
  runtimeConfig: {
    apiBaseInternal: process.env.NUXT_API_BASE_INTERNAL ?? 'http://nginx/api/v1',
    public: {
      apiBase: process.env.NUXT_PUBLIC_API_BASE ?? 'http://localhost:8080/api/v1',
      mediaBase: process.env.NUXT_PUBLIC_MEDIA_BASE ?? 'http://localhost:9000/resonance',
    },
  },

  app: {
    head: {
      title: 'Resonance — billetterie d\'événements',
      meta: [
        { name: 'viewport', content: 'width=device-width, initial-scale=1' },
        { name: 'description', content: 'Resonance — découvrez et achetez vos billets pour les événements culturels près de chez vous.' },
      ],
      link: [
        // @perf-debt: polices Google Fonts via <link> sans `font-display: swap`
        // ni self-hosting — résolu en J2 atelier "j2-bundle" (@nuxt/fonts).
        { rel: 'preconnect', href: 'https://fonts.googleapis.com' },
        { rel: 'preconnect', href: 'https://fonts.gstatic.com', crossorigin: '' },
        {
          rel: 'stylesheet',
          href: 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=auto',
        },
      ],
    },
  },
})
