# ──────────────────────────────────────────────────────────────────────────────
#  ops/mercure/Dockerfile — Image hub Mercure pour Heroku container stack
#
#  Sprint 14-C — App Heroku séparée `stayos-mercure` (cf. deploy.md §0/§4/§8).
#
#  Pourquoi une image custom plutôt qu'un `image:` direct dans heroku.yml :
#    heroku.yml `build.docker.web` exige un Dockerfile (path), pas une
#    référence d'image. On hérite donc de `dunglas/mercure` sans ajout
#    de code — toute la config se fait via Config Vars + Caddyfile interne
#    de l'image upstream.
#
#  ⚠️  Invariant CRITIQUE :
#    MERCURE_JWT_SECRET côté backend (`stayos-api`) DOIT être STRICTEMENT
#    identique à MERCURE_PUBLISHER_JWT_KEY et MERCURE_SUBSCRIBER_JWT_KEY
#    côté ce hub (`stayos-mercure`). Sinon le backend signe des JWT que le
#    hub refuse → 401 sur tout abonnement EventSource frontend.
#
#  ⚠️  Différences vs config DEV (docker-compose.yml service `mercure`) :
#    - DEV : cors_origins "*" + `anonymous` activé.
#    - PROD : cors_origins restreint au domaine front, publish_origins
#             restreint à l'origin backend, `anonymous` SUPPRIMÉ.
#    Ces différences sont posées via MERCURE_EXTRA_DIRECTIVES (Config Var
#    Heroku), pas dans cette image. Cf. README.md à côté.
# ──────────────────────────────────────────────────────────────────────────────

# Version pinned pour garder le déploiement reproductible. Bump manuel
# après vérification du changelog Mercure.
FROM dunglas/mercure:v0.16

# Pas de COPY, pas de RUN — toute la config est passée en env au runtime
# (cf. heroku.yml + Config Vars).
#
# Note : l'entrypoint upstream lance `caddy run --config /etc/caddy/Caddyfile.prod
# --adapter caddyfile`. Le Caddyfile.prod embarqué lit {$SERVER_NAME},
# {$MERCURE_PUBLISHER_JWT_KEY}, {$MERCURE_SUBSCRIBER_JWT_KEY} et inclut
# MERCURE_EXTRA_DIRECTIVES via globule.

EXPOSE 80
