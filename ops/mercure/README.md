# `stayos-mercure` — Hub Mercure prod (Heroku container)

App Heroku **séparée** de `stayos-api`. Délègue uniquement le SSE (Server-Sent
Events) — pas de logique métier, pas de base de données.

Sprint 14-C — référence courte. Le contexte complet (DNS Cloudflare, secret
partagé, SSE + Heroku, etc.) vit dans `.claude/docs/deploy.md` §0/§4/§8/§10.

## Fichiers

| Fichier | Rôle |
|---|---|
| `Dockerfile` | Hérite de `dunglas/mercure:v0.16` (image upstream, pas de patch). |
| `heroku.yml` | Build container + `run.web` qui substitue `$PORT` dans `SERVER_NAME` au runtime. |

## Invariants de sécurité

1. **Secret partagé.** `MERCURE_PUBLISHER_JWT_KEY` et `MERCURE_SUBSCRIBER_JWT_KEY`
   posés ici **DOIVENT** être strictement égaux à `MERCURE_JWT_SECRET` posé sur
   `stayos-api`. Toute divergence → 401 sur tout abonnement EventSource côté
   frontend. Le backend signe les JWT subscriber via
   `App\Shared\Mercure\MercureSubscriberTokenService` (Sprint 14-B.2.1) avec ce
   même secret.

2. **`anonymous` SUPPRIMÉ.** Le hub n'accepte plus aucun abonnement non
   authentifié. Le frontend obtient son JWT via le cookie httpOnly
   `mercureAuthorization` posé par `GET /api/mercure/token`.

3. **`publish_origins` restreint à `https://api.getstayos.com`.** Seul le
   backend peut PUBLISH sur le hub. Un tiers qui voudrait flooder le hub
   sans être l'origin backend sera rejeté.

4. **`cors_origins` restreint aux domaines tenants.** Couvre `demo.getstayos.com`
   et le wildcard `*.getstayos.com` (front multi-subdomain).

## Config Vars à poser sur `stayos-mercure`

```bash
# Secret partagé avec stayos-api — récupérer depuis le gestionnaire de mots
# de passe Massamba (cf. deploy.md §2). NE PAS régénérer indépendamment de
# stayos-api.
heroku config:set -a stayos-mercure \
    MERCURE_PUBLISHER_JWT_KEY='<même que stayos-api MERCURE_JWT_SECRET>' \
    MERCURE_SUBSCRIBER_JWT_KEY='<même que stayos-api MERCURE_JWT_SECRET>'

# Directives Caddy/Mercure additionnelles (CORS, publish_origins,
# anonymous OFF — cf. invariants ci-dessus).
heroku config:set -a stayos-mercure MERCURE_EXTRA_DIRECTIVES='cors_origins "https://demo.getstayos.com https://*.getstayos.com"
publish_origins "https://api.getstayos.com"'
```

> ⚠️  Ne pas inclure `anonymous` dans `MERCURE_EXTRA_DIRECTIVES`. Son absence
> est volontaire (cf. invariant 2).

## Push

Subtree push depuis la racine du repo monorepo :

```bash
heroku stack:set container -a stayos-mercure
heroku git:remote -a stayos-mercure -r heroku-mercure
git subtree push --prefix=ops/mercure heroku-mercure main
```

Vérifier ensuite :

```bash
heroku logs -a stayos-mercure --tail
# Attendu : "serving initial configuration", pas d'erreur de signature JWT.

curl -i 'https://mercure.getstayos.com/.well-known/mercure?topic=test'
# Attendu : 401 Unauthorized (pas de JWT subscriber) — preuve qu'`anonymous`
# est bien désactivé et que le hub répond.
```

## Limites connues

- **Proxy Cloudflare** : laisser `mercure.getstayos.com` en **DNS-only** (grey
  cloud) — le proxy orange bufferise les SSE et casse les notifications
  temps réel. Cf. `deploy.md` §8.1.
- **Heroku dyno cycling** : les dynos redémarrent toutes les ~24h. Les
  EventSource frontend doivent gérer la reconnexion (déjà fait dans
  `frontend/src/services/mercure.service.ts`).
- **Un seul dyno web** sur le plan Hobby → indisponibilité brève au cycling.
  Acceptable pour la démo, à upgrader en Standard-2X si SLA strict.
