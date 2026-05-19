// https://nuxt.com/docs/api/configuration/nuxt-config
//
// Resonance — frontend (branche solution/j2-bundle).
// Sur le starter, j2-bundle ajoute @nuxt/image (pipeline IPX AVIF/WebP)
// pour réduire le poids transféré et débloquer la LCP des hero/galerie,
// et @nuxt/fonts pour self-hoster Inter avec font-display swap + preload.
//
// @perf-debt: pas de routeRules / ISR / SWR — résolu en J1 atelier
// "j1-cdn-cache" (hors scope j2-bundle).
// @perf-fix: @nuxt/image (provider IPX) — solution/j2-bundle.
// @perf-fix: @nuxt/fonts (self-hosting Inter, font-display swap, preload
// du poids 400) — solution/j2-bundle.

export default defineNuxtConfig({
  compatibilityDate: '2025-07-15',
  devtools: { enabled: true },

  modules: [
    '@nuxtjs/tailwindcss',
    '@pinia/nuxt',
    '@nuxt/eslint',
    '@nuxt/image',
    '@nuxt/fonts',
  ],

  // ---- @nuxt/fonts — self-hosting Inter (solution/j2-bundle) ------------
  // @nuxt/fonts auto-détecte les `font-family` référencées dans le CSS
  // (Tailwind expose `font-sans: ['Inter', ...]` dans tailwind.config.ts).
  // Le module télécharge la police au build, la self-host sous /_fonts/*,
  // génère le @font-face avec `font-display: swap`, et propose le preload.
  // Plus besoin du <link rel="stylesheet" href="fonts.googleapis.com/...">
  // dans `app.head` — le module l'injecte lui-même.
  fonts: {
    families: [
      // Inter Regular preload : poids le plus utilisé (corps de texte) →
      // preload pour qu'il soit dispo dès le LCP. Les autres poids
      // (500/600/700, headings) chargent sans preload pour ne pas bloquer
      // le critical path.
      { name: 'Inter', weights: [400], styles: ['normal'], preload: true },
      { name: 'Inter', weights: [500, 600, 700], styles: ['normal'] },
    ],
    defaults: {
      fallbacks: { 'sans-serif': ['system-ui', 'sans-serif'] },
    },
  },

  // ---- @nuxt/image — pipeline IPX serveur (solution/j2-bundle) ---------
  // L'IPX tourne dans Nitro (server-side). Le browser fetche les images
  // via `/_ipx/<modifiers>/<source>` ; Nitro IPX résout `<source>`,
  // fetche, transforme (AVIF/WebP, resize) et sert.
  //
  // Pourquoi `domains: ['minio', 'localhost']` ?
  //   - Le backend Laravel sérialise les URLs sous `http://localhost:9000/...`
  //     (cf. AWS_URL dans backend/.env) — c'est ce que reçoit le browser
  //     dans le payload JSON.
  //   - À l'intérieur du container `frontend` (où IPX tourne), `localhost`
  //     pointe sur le container lui-même : impossible de fetch la source.
  //   - Le réseau Compose expose MinIO sous le hostname `minio`.
  //   - Conséquence : on ne passe **PAS** l'URL backend brute à `<NuxtImg>` ;
  //     on extrait le path relatif (cf. composables/useImageSrc.ts) et
  //     `image.baseURL` reconstitue l'URL serveur correcte côté Docker.
  //   - Pattern dual analogue à apiBase / apiBaseInternal pour /api/*.
  image: {
    provider: 'ipx',
    // `host:port` exact (cf. `parseURL(input).host` dans @nuxt/image —
    // un domain sans port ne matche pas une URL avec port).
    domains: ['minio:9000', 'localhost:9000'],
    format: ['avif', 'webp'],
    quality: 75,
    screens: {
      sm: 640,
      md: 768,
      lg: 1024,
      xl: 1280,
      '2xl': 1536,
    },
    presets: {
      hero: { modifiers: { format: 'avif,webp', quality: 80, fit: 'cover' } },
      gallery: { modifiers: { format: 'avif,webp', quality: 75, fit: 'cover' } },
      card: { modifiers: { format: 'avif,webp', quality: 70, fit: 'cover' } },
    },
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
      // @perf-fix: Google Fonts via <link> remplacé par @nuxt/fonts (cf. ci-dessus)
      // — solution/j2-bundle. Inter est désormais self-hosted sous /_fonts/*
      // avec font-display: swap implicite, et le preconnect cross-origin n'est
      // plus nécessaire (font same-origin).
      link: [],
    },
  },
})
