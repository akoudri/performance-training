#!/usr/bin/env bash
# =============================================================================
# resolve-star-slug.sh
# =============================================================================
# Résout le slug du « star event » (event ayant le plus de tickets vendus
# selon `ticket_categories.sold` agrégé) via une requête sur le container
# Compose `postgres`. Sortie : le slug sur stdout (sans saut de ligne final),
# ou un message explicatif sur stderr + exit 1 si rien trouvé.
#
# Utilisé par les cibles `make lighthouse` et `make k6` pour pointer leurs
# scénarios sur une URL stable et représentative du seed réaliste.
# =============================================================================

set -euo pipefail

# Récupère POSTGRES_USER / POSTGRES_DB depuis .env sans sourcer le fichier
# (`set -a; . .env` casse sur UID readonly présent dans le .env Compose).
read_env() {
  local key="$1"
  local default="$2"
  if [ -f .env ]; then
    local val
    val=$(grep -E "^${key}=" .env | head -1 | cut -d= -f2- || true)
    if [ -n "${val:-}" ]; then
      printf '%s' "$val"
      return
    fi
  fi
  printf '%s' "$default"
}

PG_USER="${POSTGRES_USER:-$(read_env POSTGRES_USER resonance)}"
PG_DB="${POSTGRES_DB:-$(read_env POSTGRES_DB resonance)}"

SQL='SELECT e.slug
FROM events e
JOIN event_sessions s ON s.event_id = e.id
JOIN ticket_categories tc ON tc.event_session_id = s.id
GROUP BY e.id, e.slug
ORDER BY SUM(tc.sold) DESC
LIMIT 1;'

SLUG=$(docker compose exec -T postgres psql -U "$PG_USER" -d "$PG_DB" -tAc "$SQL" 2>/dev/null | tr -d '[:space:]')

if [ -z "$SLUG" ]; then
  {
    echo "[resolve-star-slug] Aucun event trouvé dans la base."
    echo "[resolve-star-slug] La stack semble down ou non seedée. Lance :"
    echo "[resolve-star-slug]   make up && make restore"
    echo "[resolve-star-slug] (ou 'make seed-realistic' si le dump n'est pas à jour)."
  } >&2
  exit 1
fi

printf '%s' "$SLUG"
