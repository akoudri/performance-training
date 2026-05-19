/**
 * Réécrit les URLs d'images sérialisées par le backend Laravel pour qu'elles
 * soient résolvables côté serveur Nitro (où IPX tourne) sur le réseau Docker
 * Compose `resonance`.
 *
 * Pourquoi : `Storage::disk('s3')->url(...)` (Laravel) sérialise les URLs
 * MinIO sous la forme `http://localhost:9000/resonance/<path>` — c'est ce
 * que le browser reçoit dans le payload JSON. Mais à l'intérieur du
 * container `frontend` (où Nitro et IPX tournent), `localhost:9000` pointe
 * sur le container lui-même, pas sur MinIO. Le réseau Compose expose MinIO
 * sous le hostname `minio`.
 *
 * Le browser ne consomme jamais directement la chaîne renvoyée par cette
 * fonction : `<NuxtImg>` génère une URL `/_ipx/<modifiers>/<src>`, et c'est
 * IPX (server-side, dans Nitro) qui fait le fetch HTTP de la source. La
 * réécriture est donc safe côté browser : la string `http://minio:9000/...`
 * apparaît juste comme paramètre d'URL pour `/_ipx/...`, jamais comme cible
 * d'un fetch direct.
 *
 * Pattern dual analogue à apiBase / apiBaseInternal pour /api/* (cf.
 * composables/useApi.ts).
 *
 * @perf-fix: @nuxt/image (provider IPX) — solution/j2-bundle.
 */
export function useImageSrc(url: string | null | undefined): string {
  if (!url) return ''
  return url.replace('://localhost:9000/', '://minio:9000/')
}
