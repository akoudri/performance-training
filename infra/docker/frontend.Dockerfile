# =============================================================================
# Resonance — image frontend Nuxt 4 (Node 22) — starter prod-like
# =============================================================================
# Node 22 LTS : @nuxt/image v2 requiert Node ≥ 22 (engines field). Le bump
# 20 → 22 a été fait dans solution/j2-bundle pour permettre l'install de
# @nuxt/image. Aucun impact fonctionnel sur le starter (Nitro fonctionne
# identiquement sur les deux versions).
# =============================================================================
# Phase 4-bis : le starter tourne en mode prod-like. L'image est construite
# en multi-stage : `builder` lance `npm ci` + `nuxt build` ; `runtime`
# n'embarque que `.output/` et exécute `node .output/server/index.mjs`.
#
# Plus de hot reload : toute modif du code frontend nécessite un rebuild
# (`make frontend-rebuild`). C'est volontaire — le starter est un
# environnement de mesure, pas un environnement de dev.
#
# Le tuning Nitro (preset, ISR, swr, image, fonts, code splitting) reste à
# faire dans les branches `solution/jX-name` ; aucun marqueur @perf-debt
# côté Dockerfile car ce fichier n'introduit pas de dette : c'est juste
# un substrat d'exécution standard.
# =============================================================================

# ---- Stage 1 : builder -----------------------------------------------------

FROM node:22-alpine AS builder

WORKDIR /app

# Install des dépendances en couche dédiée pour bénéficier du cache Docker
# (les manifests changent moins souvent que le source).
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

# Source complet (filtré par .dockerignore : pas de node_modules / .output).
COPY . .

# Build prod : génère .output/ (Nitro Node-server par défaut).
RUN npm run build

# ---- Stage 2 : runtime -----------------------------------------------------

FROM node:22-alpine AS runtime

ENV NODE_ENV=production

WORKDIR /app

# tini comme PID 1 pour propager SIGTERM proprement à node (sinon Compose
# attend 10s avant de SIGKILL — friction inutile au stop).
RUN apk add --no-cache tini

COPY --from=builder /app/.output ./.output

# Le user `node` (UID 1000) existe par défaut dans node:22-alpine et a accès
# à /app. Pas de bind-mount au runtime — le source du frontend reste hors
# du container.
USER node

EXPOSE 3000

ENTRYPOINT ["/sbin/tini", "--"]
CMD ["node", ".output/server/index.mjs"]
