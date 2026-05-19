// https://nuxt.com/docs/api/configuration/nuxt-config
//
// Resonance — frontend starter (cf. resonance-spec.md §8).
// Configuration volontairement minimaliste : pas de @nuxt/image, pas de
// @nuxt/fonts, pas de routeRules — toutes ces optims sont introduites dans
// les branches solution/jX-name.
//
// @perf-debt: pas de routeRules / ISR / SWR — résolu en J1 atelier
// "j1-cdn-cache".
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
