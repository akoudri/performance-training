#!/bin/sh
# =============================================================================
# Resonance — Bootstrap PgBouncer auth_query (SCRAM passthrough)
# =============================================================================
# Exécuté UNE FOIS, lors du premier `initdb` du volume postgres_data
# (scripts dans /docker-entrypoint-initdb.d/, cf. docker-compose.yml).
#
# Architecture :
#   - PgBouncer s'authentifie en tant qu'utilisateur "pgbouncer" (rôle
#     dédié, password depuis l'env PGBOUNCER_PASSWORD du compose).
#   - Pour chaque client (resonance, …), PgBouncer interroge la fonction
#     pgbouncer.user_lookup() côté Postgres via auth_query pour obtenir le
#     hash SCRAM, puis valide la réponse SASL du client.
#   - Aucun hash n'est committé : seul le cleartext PGBOUNCER_PASSWORD
#     transite via l'env (compose), jamais dans le repo.
#
# Survie aux make restore : le rôle pgbouncer est cluster-level (volume
# postgres_data), le schéma pgbouncer est en dehors de "public" (que la
# commande resonance:restore-database drop/recrée). Donc auth préservée
# à travers tous les restores du dataset applicatif.
#
# Pour réinitialiser (formation, debugging) :
#   docker compose down -v && docker compose up -d
# Le volume est recréé, ce script ré-exécuté, l'auth restaurée.
# =============================================================================

set -e

PGBOUNCER_PASSWORD="${PGBOUNCER_PASSWORD:?PGBOUNCER_PASSWORD env var is required for first init}"

psql -v ON_ERROR_STOP=1 \
    --username "$POSTGRES_USER" \
    --dbname "$POSTGRES_DB" \
    --set "pgbouncer_password=${PGBOUNCER_PASSWORD}" <<-'EOSQL'
    -- Rôle PgBouncer (cluster-level, survit aux DROP DATABASE).
    CREATE ROLE pgbouncer LOGIN PASSWORD :'pgbouncer_password';

    -- Schéma + fonction (DB-level, mais hors "public" → préservés par
    -- resonance:restore-database qui ne drop QUE le schéma public).
    CREATE SCHEMA pgbouncer;

    -- Fonction de lookup pour PgBouncer auth_query.
    -- SECURITY DEFINER : exécutée en tant que propriétaire (POSTGRES_USER,
    -- superuser au moment du bootstrap initdb), donc capable de lire
    -- pg_shadow. Sans SECURITY DEFINER, le rôle pgbouncer n'aurait pas
    -- les droits requis et l'auth_query échouerait.
    CREATE FUNCTION pgbouncer.user_lookup(IN i_username TEXT,
        OUT uname TEXT, OUT phash TEXT) RETURNS RECORD AS $$
    BEGIN
        SELECT usename, passwd FROM pg_shadow
        WHERE usename = i_username
        INTO uname, phash;
        RETURN;
    END;
    $$ LANGUAGE plpgsql SECURITY DEFINER;

    REVOKE ALL ON FUNCTION pgbouncer.user_lookup(TEXT) FROM PUBLIC;
    GRANT EXECUTE ON FUNCTION pgbouncer.user_lookup(TEXT) TO pgbouncer;
    GRANT USAGE ON SCHEMA pgbouncer TO pgbouncer;
EOSQL

echo "Resonance — PgBouncer auth role + schema + user_lookup() installés."
