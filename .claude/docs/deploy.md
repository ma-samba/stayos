# Déploiement — Référence

Procédure complète pour mettre StayOS en production sur
`demo.getstayos.com`. Document de référence unique — toute
divergence avec le code doit être tranchée en faveur du code,
puis ce doc est mis à jour.

> ⚠️ Domaine de prod : **getstayos.com**. L'ancien `stayos.sn`
> est abandonné — toute occurrence résiduelle dans le code,
> les fixtures ou les templates email doit être corrigée
> avant le go-live.

---

## 0. Architecture cible

```
                          Cloudflare DNS (+ proxy)
                                   │
            ┌──────────────────────┼──────────────────────┐
            │                      │                      │
       demo.getstayos.com    api.getstayos.com     mercure.getstayos.com
       (frontend Vercel)     (Heroku web dyno)     (Heroku container)
                                   │                      │
                                   ├── Heroku Postgres (DATABASE_URL injecté)
                                   ├── Heroku Data for Redis (REDIS_URL injecté)
                                   ├── Heroku worker dyno (messenger:consume)
                                   └── Heroku release phase (migrations)

       *.getstayos.com  →  Vercel (résolution tenant par subdomain côté front)
```

**Apps Heroku**
- `stayos-api` (container) — 3 process types :
  - `web` : Symfony (FPM + serveur HTTP, écoute `$PORT`)
  - `worker` : `php bin/console messenger:consume async --time-limit=3600`
  - `release` : `doctrine:migrations:migrate` + migrations tenant
- `stayos-mercure` (container) — hub Caddy `dunglas/mercure`,
  écoute `$PORT`, TLS terminé par Heroku.

**Flux**
1. Le navigateur charge `demo.getstayos.com` → bundle Vue Vercel.
2. L'app Vue appelle `https://api.getstayos.com/api/*` (REST).
3. L'app Vue ouvre `https://mercure.getstayos.com/.well-known/mercure`
   (SSE), authentifiée par un cookie JWT subscriber émis par le
   backend (Sprint 14-B.2.1).
4. Le worker Messenger envoie les emails Mailjet, les notifications
   Mercure, et les jobs asynchrones.

---

## 1. Pré-requis & comptes

| Compte | État | Usage |
|---|---|---|
| GitHub `ma-samba/stayos` | Ouvert | Source de vérité, CI |
| Heroku (CB + 2FA) | Ouvert | `stayos-api`, `stayos-mercure`, add-ons |
| Vercel Hobby (`stayos-frontend` importé) | Ouvert, builds en pause | Frontend Vue |
| Sentry EU (2 projets : backend + frontend) | Ouvert | Erreurs runtime |
| UptimeRobot | Ouvert | Uptime + status page |
| Cloudflare (domaine `getstayos.com`) | Ouvert | DNS + proxy |
| Paydunya (sandbox + live) | À confirmer | Paiements |
| Mailjet | À confirmer | Emails transactionnels |
| Uploadcare | À confirmer | CDN images |

**CLI nécessaires** :
```bash
brew install heroku/brew/heroku
brew install vercel-cli      # optionnel — la console Vercel suffit
heroku login
heroku container:login       # pour le push d'image
```

---

## 2. ⚠️ Secrets à régénérer AVANT prod

Le repo contient des **placeholders de dev** qui NE DOIVENT
JAMAIS partir tels quels en prod. Régénérer ces 3 secrets,
les stocker dans un gestionnaire (1Password, Bitwarden, etc.),
**ne jamais committer**.

| Secret | Valeur dev (`.env`) | Action prod |
|---|---|---|
| `APP_SECRET` | `change_me_for_production_32_chars_min` | `openssl rand -hex 32` |
| `JWT_PASSPHRASE` | `stayos_jwt_dev_secret` | `openssl rand -hex 32` |
| `MERCURE_JWT_SECRET` | `stayos_mercure_dev_secret_min_32bytes_long_pad` | `openssl rand -hex 32` |

### 2.1 Paire de clés JWT (Lexik) — base64 via Config Vars

**Stratégie actée Sprint 14-C.1.** Les .pem ne sont JAMAIS dans
l'image prod (`backend/.dockerignore` exclut `config/jwt/*.pem`).
En prod, l'override `when@prod` de
`backend/config/packages/lexik_jwt_authentication.yaml` lit les clés
encodées **base64** depuis 2 Config Vars Heroku, décodées à la volée
par le processeur Symfony `base64:`. Lexik 3.x reçoit le contenu
PEM brut, le détecte comme contenu (et non un chemin) via
`is_file()` et le charge en mémoire — aucune I/O fichier nécessaire.

**Mode opératoire** :

```bash
# 1. Dans un environnement éphémère (poste de prod), avec la passphrase
#    PROD (≠ passphrase dev) :
export JWT_PASSPHRASE='<passphrase prod>'
php bin/console lexik:jwt:generate-keypair --overwrite

# 2. Encoder les deux PEM en une ligne base64 (sans newline) :
#    Linux :
base64 -w0 config/jwt/private.pem
base64 -w0 config/jwt/public.pem
#    macOS :
base64 -i config/jwt/private.pem | tr -d '\n'
base64 -i config/jwt/public.pem  | tr -d '\n'

# 3. Stocker les 3 valeurs (passphrase + 2 base64) dans le
#    gestionnaire de secrets Massamba. SUPPRIMER les .pem locaux dès
#    que les valeurs sont en sécurité.

# 4. Poser sur Heroku :
heroku config:set -a stayos-api \
    JWT_SECRET_KEY_B64='<base64 du private.pem>' \
    JWT_PUBLIC_KEY_B64='<base64 du public.pem>' \
    JWT_PASSPHRASE='<passphrase prod>'
```

⚠️ **NE PAS poser** `JWT_SECRET_KEY` ni `JWT_PUBLIC_KEY` (chemins)
en prod : l'override `when@prod` ne les lit plus, ce sont des
reliquats dev/test.

**Pourquoi base64 plutôt que le PEM brut en Config Var** : un PEM
contient des newlines, mal gérées par les Config Vars Heroku.
Base64 = ligne unique safe.

**Garde-fou build-time** : `Dockerfile.prod` injecte des stubs
base64 `placeholder\n` SCOPÉS au `RUN` de `cache:warmup` (cf.
Dockerfile commentaire détaillé), pour absorber tout risque que
le warmup tente d'évaluer les env JWT au build. Les stubs ne
persistent pas dans l'image — ils sont écrasés par les Config
Vars Heroku au runtime.

### 2.2 Invariant `MERCURE_JWT_SECRET`

Le secret signe les JWT côté backend (`MercureSubscriberTokenService`,
Sprint 14-B.2.1) et vérifie les JWT côté hub Mercure
(`MERCURE_PUBLISHER_JWT_KEY` + `MERCURE_SUBSCRIBER_JWT_KEY`).
**Il DOIT être strictement identique** dans les deux apps
Heroku, sinon l'EventSource frontend reçoit 401 sur tout
abonnement.

---

## 3. Config Vars Heroku — app `stayos-api`

Tableau exhaustif aligné sur `backend/.env`. La colonne
**Source prod** indique d'où vient la valeur (généré,
add-on Heroku, dashboard service tiers, fixe).

| Variable | Valeur dev | Source prod |
|---|---|---|
| `APP_ENV` | `dev` | Fixe : `prod` |
| `APP_DEBUG` | `1` | Fixe : `0` |
| `APP_SECRET` | `change_me_for_production_32_chars_min` | Régénéré (§2) |
| `APP_VERSION` | `1.0.0` | Bump à chaque release (`git tag`) |
| `APP_DOMAIN` | `stayos.localhost` | Fixe : `getstayos.com` |
| `APP_BACKEND_URL` | `http://localhost:8080` | `https://api.getstayos.com` (callbacks Paydunya) |
| `DATABASE_URL` | `postgresql://stayos_user:...@db:5432/stayos_db?...` | **Injecté par l'add-on Heroku Postgres** — voir §3.1 |
| `REDIS_URL` | `redis://redis:6379` | **Injecté par l'add-on Heroku Data for Redis** — voir §3.2 |
| `REDIS_HOST` | `redis` | À retirer (legacy dev, plus utilisé en prod) |
| `REDIS_PORT` | `6379` | À retirer (legacy dev, plus utilisé en prod) |
| `MESSENGER_TRANSPORT_DSN` | `redis://redis:6379/messages` | Dériver de `REDIS_URL` — voir §3.2 |
| `JWT_SECRET_KEY` | `%kernel.project_dir%/config/jwt/private.pem` | ⛔ **NE PAS POSER** — `when@prod` ignore cette variable (cf. §2.1) |
| `JWT_PUBLIC_KEY` | `%kernel.project_dir%/config/jwt/public.pem` | ⛔ **NE PAS POSER** — idem |
| `JWT_SECRET_KEY_B64` | (n/a dev) | Base64 (1 ligne) de `config/jwt/private.pem` régénéré §2.1 |
| `JWT_PUBLIC_KEY_B64` | (n/a dev) | Base64 (1 ligne) de `config/jwt/public.pem` régénéré §2.1 |
| `JWT_PASSPHRASE` | `stayos_jwt_dev_secret` | Régénéré (§2) — passphrase utilisée à la génération des `*.pem` |
| `MAILER_DSN` | `smtp://mailpit:1025` | Mailjet : `smtp://API_KEY:API_SECRET@in-v3.mailjet.com:587?encryption=tls` (depuis dashboard Mailjet) |
| `MAILER_FROM` | `noreply@stayos.sn` | `noreply@getstayos.com` |
| `MERCURE_URL` | `http://mercure:9090/.well-known/mercure` | URL **interne** vue du backend : `https://mercure.getstayos.com/.well-known/mercure` (Heroku → Heroku passe par l'edge public) |
| `MERCURE_PUBLIC_URL` | `http://localhost:9090/.well-known/mercure` | URL **publique** annoncée dans les claims JWT : `https://mercure.getstayos.com/.well-known/mercure` |
| `MERCURE_JWT_SECRET` | `stayos_mercure_dev_secret_...` | Régénéré (§2.2) — **identique** côté `stayos-mercure` |
| `FRONTEND_URL` | `http://localhost:5173` | `https://demo.getstayos.com` |
| `SUPERADMIN_URL` | `http://superadmin.localhost:8080` | `https://superadmin.getstayos.com` (si subdomain dédié) OU `https://demo.getstayos.com/superadmin` selon l'archi retenue |
| `CORS_ALLOW_ORIGIN` | `^https?://(localhost\|127\.0\.0\.1)(:[0-9]+)?$` | ⚠️ **Reliquat non lu** — `nelmio_cors.yaml` utilise `FRONTEND_URL` / `SUPERADMIN_URL` dans `when@prod`, **pas** cette variable. Ne pas la setter en prod (ou la supprimer du `.env`). Voir §3.3. |
| `LOCK_DSN` | `flock` | `flock` (lock par fichier local au dyno — suffit pour le worker mono-dyno) |
| `DEFAULT_URI` | `http://localhost` | `https://api.getstayos.com` |
| `PAYDUNYA_MODE` | `test` | `live` (au go-live) — `test` acceptable pour `demo.` |
| `PAYDUNYA_MASTER_KEY` | (vide) | Depuis dashboard Paydunya (compte live) |
| `PAYDUNYA_PRIVATE_KEY` | (vide) | Depuis dashboard Paydunya |
| `PAYDUNYA_TOKEN` | (vide) | Depuis dashboard Paydunya |
| `PAYDUNYA_HASH_VERIFICATION_ENABLED` | `false` | **`true` en prod** (Sprint 14-B.1.2.2) |
| `UPLOADCARE_PUBLIC_KEY` | (vide) | Depuis dashboard Uploadcare (projet prod) |
| `UPLOADCARE_SECRET_KEY` | (vide) | Depuis dashboard Uploadcare |
| `SENTRY_DSN` | (vide) | Depuis dashboard Sentry (projet `stayos-backend`) |

### 3.1 `DATABASE_URL` — pièges Heroku Postgres (RÉSOLU Sprint 14-C.2)

L'add-on Heroku Postgres injecte automatiquement `DATABASE_URL`
au format :
```
postgres://USER:PASS@HOST:5432/DB
```

Doctrine 4 ne consomme pas ce format directement (préfixe interdit,
absence de `serverVersion`, absence de `sslmode` alors qu'Heroku
Postgres impose SSL).

**Solution actée** : un `EnvVarProcessor` custom `heroku_db`
(`App\Shared\Env\HerokuDatabaseUrlProcessor`) câblé UNIQUEMENT en
prod via `when@prod` dans `config/packages/doctrine.yaml` :

```yaml
when@prod:
    doctrine:
        dbal:
            url: '%env(heroku_db:resolve:DATABASE_URL)%'
```

Le processeur normalise de manière **idempotente** :

1. `postgres://` → `postgresql://` (préfixe de tête).
2. Ajout de `sslmode=require` si absent.
3. Ajout de `serverVersion=16` si absent.

→ En prod **on ne touche PAS manuellement à `DATABASE_URL`** :
l'add-on l'injecte au format Heroku natif, le processeur la
normalise à chaque résolution. Aucune Config Var
`DATABASE_URL_OVERRIDE` n'est nécessaire (cette piste mentionnée
avant 14-C.2 est ABANDONNÉE).

**Idempotence stricte** : appliqué N fois = même résultat. Et le
processeur ne TOUCHE PAS aux valeurs déjà posées (si un opérateur
veut surcharger `serverVersion=17` lors d'un bump PG, sa valeur
est respectée — testé dans `tests/Unit/Env/HerokuDatabaseUrlProcessorTest.php`).

**Fallback parse_url** : si l'URL contient des caractères spéciaux
non URL-encodés dans le mot de passe (qui font tomber `parse_url()`),
le processeur se rabat sur le seul remplacement de schéma. Une URL
imparfaite mais fonctionnelle vaut mieux qu'une URL agressivement
réécrite et corrompue. Workaround opérateur : URL-encoder le mot
de passe (les caractères `@`, `:`, `?`, `#`, `/` notamment).

**Dev/test intouchés** : la ligne principale `url: '%env(resolve:DATABASE_URL)%'`
reste utilisée pour ces envs (URLs déjà au bon format dans `.env` /
`.env.test`).

### 3.2 `REDIS_URL` — TLS

L'add-on Heroku Data for Redis injecte `REDIS_URL` (et
parfois `REDIS_TLS_URL` selon le plan). Sur les plans
Premium / Hobby récents, l'URL est `rediss://` (TLS).

- Le code (HealthController, pool `kpi.cache`, Messenger)
  lit `REDIS_URL` — pas de changement.
- `MESSENGER_TRANSPORT_DSN` doit pointer sur le même Redis :
  `${REDIS_URL}/messages` (path différent pour isoler la
  queue Messenger du cache KPI). **Vérifier que la lib Redis
  PECL embarquée dans l'image Docker supporte `rediss://`** —
  sinon ajouter `?ssl_verify_peer=0` ou bump la lib.
- Les variables `REDIS_HOST` / `REDIS_PORT` du `.env` dev
  sont des reliquats — aucun code applicatif ne les lit
  (vérifié au Sprint 14-B). Ne **pas** les setter en prod.

### 3.3 CORS prod — piloté par `FRONTEND_URL` / `SUPERADMIN_URL`

Le CORS prod est entièrement piloté par le bloc `when@prod` de
`config/packages/nelmio_cors.yaml` (refacto Sprint 14-A.3 B.2),
qui lit :

- `%env(FRONTEND_URL)%` (= `https://demo.getstayos.com`) sur
  les paths `^/api` et `^/public`
- `%env(SUPERADMIN_URL)%` sur le path `^/superadmin`
- Plus une regex hardcodée `^https://stayos.*\.vercel\.app$`
  sur `^/api` et `^/public` pour conserver l'autorisation des
  preview deploys Vercel par PR

Aucune autre variable d'env n'intervient. La variable
`CORS_ALLOW_ORIGIN` du `.env` dev n'est référencée NULLE PART
dans `nelmio_cors.yaml` — c'est un reliquat à ignorer en prod.

**Conséquence** : poser correctement `FRONTEND_URL` et
`SUPERADMIN_URL` (cf. tableau §3) suffit dans le cas mono-domaine.

#### ⚠️ Point d'attention conditionnel — multi-subdomain front

`TenantMiddleware` résout le tenant **en priorité 1 via le
header `X-Tenant-Slug`**, puis en priorité 2 via le subdomain
de l'`Host`. Deux architectures front sont donc possibles :

- **Mono-domaine** (par défaut) : tout le frontend est servi
  sur `demo.getstayos.com`, le slug tenant est passé en header
  `X-Tenant-Slug` sur chaque appel API. → `FRONTEND_URL` seul
  couvre les origins, **aucune config CORS supplémentaire
  nécessaire**.
- **Multi-subdomain front** : les tenants sont servis sur des
  sous-domaines distincts (`savana.getstayos.com`,
  `villa-collines.getstayos.com`, etc., cf. §8.2). L'`Origin`
  HTTP envoyé par le navigateur sera alors le sous-domaine,
  **non couvert** par `FRONTEND_URL` → les appels API seront
  rejetés par CORS.

**TODO 14-C CONDITIONNEL** : si l'option multi-subdomain front
est retenue (décision §8.2), élargir le `when@prod` de
`nelmio_cors.yaml` AVANT le go-live en ajoutant une regex
type :
```yaml
allow_origin:
    - '%env(FRONTEND_URL)%'
    - '^https://[a-z0-9-]+\.getstayos\.com$'
    - '^https://stayos.*\.vercel\.app$'
```
sur `^/api` et `^/public`. Sans ce fix, tous les appels API
des tenants en subdomain seront bloqués par CORS. À trancher
en même temps que la config DNS wildcard du §8.

---

## 4. Config Vars Heroku — app `stayos-mercure`

Le hub `dunglas/mercure` est piloté entièrement par variables
d'env.

| Variable | Valeur prod |
|---|---|
| `SERVER_NAME` | `:$PORT` (Heroku injecte `$PORT`) |
| `MERCURE_PUBLISHER_JWT_KEY` | = `MERCURE_JWT_SECRET` du backend (§2.2) |
| `MERCURE_SUBSCRIBER_JWT_KEY` | = `MERCURE_JWT_SECRET` du backend (§2.2) |
| `MERCURE_EXTRA_DIRECTIVES` | Bloc multi-ligne — voir ci-dessous |

```caddy
# Valeur prod de MERCURE_EXTRA_DIRECTIVES
cors_origins "https://demo.getstayos.com https://*.getstayos.com"
publish_origins "https://api.getstayos.com"
# anonymous SUPPRIMÉ (≠ dev) — tout abonnement exige un JWT valide
```

**Différences vs dev** (`docker-compose.yml`) :
- Dev : `cors_origins "*"` + `anonymous` activés.
- Prod : `cors_origins` restreint au domaine, `publish_origins`
  restreint à l'origin du backend, `anonymous` **supprimé**.
- Dev : `SERVER_NAME: ':9090'`.
- Prod : `SERVER_NAME: ':$PORT'`.

**TLS** : pas de Let's Encrypt côté Caddy. Heroku termine TLS
en façade et forward du HTTP au dyno. Le `SERVER_NAME` reste
HTTP-only (`:$PORT`), Caddy ne tente pas d'obtenir un certif.

> Ces Config Vars sont posées via `heroku config:set` à
> l'étape §11. Les fichiers de déploiement associés
> (`heroku.yml` + image Mercure) sont produits à l'étape 2
> du Sprint 14-C (séparée).

---

## 5. Image backend pour Heroku — DÉCISION : Option B (FrankenPHP)

**État dev** (`docker/php/Dockerfile`, à la racine du repo) :
- Base `php:8.4-fpm-alpine` + Nginx séparé via docker-compose.
- **INCHANGÉ** — Sprint 14-C ne touche pas la stack dev.

**Décision actée (Sprint 14-C étape 2)** : **Option B — FrankenPHP**.

Pourquoi B plutôt que A (Nginx+FPM+Supervisor) ou C (buildpack
heroku/php) :
- 1 seul binaire qui parle HTTP nativement sur `$PORT` → pas de
  supervisor à orchestrer, signal handling propre côté Heroku.
- Maintenu par l'équipe Symfony (Kevin Dunglas) — c'est le chemin
  d'évolution naturel et il convergera avec Mercure embarqué si
  l'on veut un jour fusionner les deux apps Heroku.
- Image plus légère qu'un combo Nginx + PHP-FPM + Supervisor.

### Fichiers produits Sprint 14-C étape 2

| Fichier | Rôle |
|---|---|
| `backend/docker/php/Dockerfile.prod` | Image FrankenPHP multi-stage (vendor + runtime) avec extensions `pdo_pgsql`, `redis`, `bcmath`, `intl`, `gd`, `opcache`, `zip`, `mbstring`, `sysvsem` via `install-php-extensions`. Worker mode FrankenPHP **désactivé** (cf. ci-dessous). |
| `backend/docker/php/Caddyfile` | Routing minimal : `auto_https off`, `admin off`, `php_server` vers `/app/public`. **Ne re-pose AUCUN security header** — `SecurityHeadersSubscriber` côté Symfony s'en charge déjà, dupliquer créerait des contradictions. |
| `backend/heroku.yml` | Heroku container stack : build `web` + `worker` (même image, commandes différentes), release phase = `doctrine:migrations:migrate` + `stayos:tenant:migrate` + `cache:clear --env=prod`. |
| `backend/.dockerignore` | Exclut `vendor/`, `var/cache/`, `tests/`, `.env.local`, `config/jwt/*.pem`, `.git/`, etc. **Aucun secret possible dans l'image.** |
| `ops/mercure/Dockerfile` | Hérite de `dunglas/mercure:v0.16` (image upstream, pas de patch). |
| `ops/mercure/heroku.yml` | Build container + wrapping `sh -c 'SERVER_NAME=":$PORT" exec caddy run …'` pour substituer `$PORT` au runtime. |
| `ops/mercure/README.md` | Pas-à-pas push + Config Vars + invariants sécurité (secret partagé, `anonymous` OFF, `publish_origins` restreint). |

### Stratégie de push — subtree push (heroku.yml dans backend/)

`heroku.yml` doit être à la racine du repo POUSSÉ. Le backend
étant en sous-dossier, on utilise un **git subtree push** :
```bash
git subtree push --prefix=backend heroku-api main
git subtree push --prefix=ops/mercure heroku-mercure main
```
→ Build context Docker = `backend/` (resp. `ops/mercure/`), le
`.dockerignore` local s'applique naturellement, le frontend/
n'invalide pas le cache Docker du backend.

Alternative non retenue : heroku.yml à la racine du repo + paths
Docker préfixées par `backend/`. Pollue le Dockerfile et inclut
frontend/ dans le contexte Docker. Si besoin un jour, déplacer
`heroku.yml` à la racine et ajuster les paths — aucune logique
applicative n'en dépend.

### ⚠️ Worker mode FrankenPHP DÉSACTIVÉ — décision de sécurité multi-tenant

StayOS est multi-tenant via `SET search_path TO hotel_{uuid}, public`
(TenantMiddleware, exécuté à chaque requête). En worker mode
FrankenPHP, le même process PHP traite plusieurs requêtes
successives sans réinitialiser l'état global.

**Risque** : fuite de `search_path` / contexte tenant entre 2
requêtes consécutives → un tenant qui voit les données d'un
autre. C'est exactement le contraire de l'invariant sécurité de
la plateforme.

→ Mode classique request/response (variable `FRANKENPHP_CONFIG`
**non positionnée**). Ré-évaluable plus tard si l'on durcit le
reset d'état inter-requêtes (kernel.request listener qui clear
TenantContext + reset search_path), avec tests dédiés. Pas avant.

### ✅ Stratégie clés JWT : voir §2.1 (implémentée Sprint 14-C.1)

Override `when@prod` dans
`backend/config/packages/lexik_jwt_authentication.yaml` lit
`JWT_SECRET_KEY_B64` / `JWT_PUBLIC_KEY_B64` via le processeur
Symfony `base64:`. Stubs `placeholder\n` SCOPÉS au `RUN
cache:warmup` du `Dockerfile.prod` pour absorber tout risque
d'évaluation env au build. Mode opératoire complet §2.1.

### Worker Messenger

Dyno séparé déclaré dans `backend/heroku.yml` (process type
`worker`) :
```
php bin/console messenger:consume async --time-limit=3600 --memory-limit=256M -vv
```
Le `--memory-limit=256M` ajoute un garde-fou anti-fuite mémoire,
le dyno Hobby a 512M.

---

## 6. Migrations en prod (release phase)

StayOS a deux jeux de migrations :

1. **Migrations globales** (`backend/migrations/`) — schema
   `public` Postgres. Appliquées par
   `doctrine:migrations:migrate`.
2. **Migrations tenant** (`backend/migrations/Tenant/`) —
   appliquées à **tous** les schemas `hotel_{uuid}` existants.
   Enregistrées dans `App\Platform\Tenant\Domain\Migration\TenantMigrationRegistry`,
   appliquées par la commande dédiée `stayos:tenant:migrate`.

**Release phase Heroku** (déclarée dans `backend/heroku.yml`,
section `release.command` — le container stack ignore `Procfile`) :

```
release: php bin/console doctrine:migrations:migrate --no-interaction \
      && php bin/console stayos:tenant:migrate \
      && php bin/console cache:clear
```

Équivalences dev → prod :

| Dev (Makefile) | Prod (Heroku) |
|---|---|
| `make migrate` | Release phase auto |
| `make migrate-tenant-dry` | `heroku run -a stayos-api php bin/console stayos:tenant:migrate --dry-run` |
| `make migrate-tenant-all` | Release phase auto |
| (provisionner un tenant) | `heroku run -a stayos-api php bin/console stayos:tenant:provision <slug>` |
| (réparer HotelProfile manquant) | `heroku run -a stayos-api php bin/console stayos:tenant:ensure-hotel-profile` |
| (nettoyer schemas orphelins) | `heroku run -a stayos-api php bin/console stayos:tenant:cleanup-orphans` |

> Le service Docker local s'appelle `php` (d'où le
> `docker compose exec php …` dans le Makefile). Sur Heroku
> c'est `heroku run …`.

> **Snapshot recommandé avant chaque release qui touche aux
> migrations** : `heroku pg:backups:capture -a stayos-api`.

---

## 7. Frontend Vercel

### 7.1 Variables d'environnement Vercel

À configurer dans le dashboard Vercel → Settings → Environment
Variables (scope `Production`). Les noms suivent **exactement**
ce que le code Vue consomme (vérifié par grep `import.meta.env.VITE_*`
sur `frontend/src/`).

| Variable | Valeur prod |
|---|---|
| `VITE_API_URL` | `https://api.getstayos.com/api` |
| `VITE_MERCURE_URL` | `https://mercure.getstayos.com/.well-known/mercure` |
| `VITE_DEFAULT_TENANT_SLUG` | (vide) — en prod, le tenant est résolu par le subdomain |
| `VITE_SUPERADMIN_URL` | `https://superadmin.getstayos.com` (si subdomain dédié) OU vide si SuperAdmin reste sur le même host que le PMS |
| `VITE_SENTRY_DSN` | DSN du projet Sentry frontend (cf. §9.1). Si vide → SDK ne s'initialise pas (silencieux). |

**Notes** :
- `VITE_UPLOADCARE_PUBLIC_KEY` et `VITE_APP_DOMAIN` sont
  déclarées dans `frontend/.env.example` et
  `frontend/.env.local.template` mais **AUCUN code Vue ne les
  consomme** au Sprint 14-B (vérifié par grep sur `src/`).
  Décision à prendre : (a) les supprimer des templates pour
  ne pas induire en erreur, ou (b) les setter quand même côté
  Vercel si l'intégration Uploadcare frontend est imminente.
- `VITE_SENTRY_DSN` est consommée par `frontend/src/main.ts`
  (intégration `@sentry/vue` Sprint 14-C). Activation
  **conditionnelle** : si la variable est vide ou absente,
  `Sentry.init()` n'est pas appelé → pas de bruit en dev.

### 7.2 Build & déploiement

`frontend/vercel.json` (existant) :
```json
{
  "buildCommand": "npm run build",
  "outputDirectory": "dist",
  "framework": "vue",
  "rewrites": [{ "source": "/(.*)", "destination": "/index.html" }],
  "headers": [{
    "source": "/assets/(.*)",
    "headers": [{ "key": "Cache-Control",
                  "value": "public, max-age=31536000, immutable" }]
  }]
}
```

Suffit en l'état. **Réactiver les builds** (projet actuellement
en pause) :
- Dashboard Vercel → projet `stayos-frontend` → Settings →
  Git → toggle `Production Branch` (= `main`).
- Vérifier que les variables d'env sont posées (§7.1) AVANT
  le premier build (Vite inline les `VITE_*` dans le bundle).

### 7.3 Domaine

Dashboard Vercel → Settings → Domains → ajouter
`demo.getstayos.com`. Vercel fournit la cible CNAME à poser
côté Cloudflare (§8). Le certificat TLS est automatique.

### 7.4 Headers de sécurité frontend (CSP)

`frontend/vercel.json` pose une CSP stricte + headers complémentaires
sur toutes les routes (Sprint 14-C polish).

**Directives CSP** (commentée par domaine autorisé) :

| Directive | Valeur | Pourquoi |
|---|---|---|
| `default-src` | `'self'` | Tout ce qui n'est pas explicitement autorisé est bloqué. |
| `script-src` | `'self'` | Vite build = aucun inline script ni eval en prod (pas de `plugin-legacy` activé). Si un blanc-écran apparaît, examiner la console pour repérer un éventuel script inline et ajuster (mais c'est anormal pour un build Vite 5 / Vue 3.5). |
| `style-src` | `'self' 'unsafe-inline' https://cdn.jsdelivr.net` | Vue + Tailwind injectent des `<style>` inline → `'unsafe-inline'` requis. `cdn.jsdelivr.net` sert la CSS Tabler Icons (cf. `index.html`). |
| `img-src` | `'self' data: https://ucarecdn.com` | `data:` pour icônes inline base64 ; `ucarecdn.com` = CDN Uploadcare (logos hôtels, photos chambres). |
| `font-src` | `'self' data: https://cdn.jsdelivr.net` | Web fonts Tabler Icons servies depuis jsDelivr. |
| `connect-src` | `'self' https://api.getstayos.com https://mercure.getstayos.com https://*.ingest.sentry.io https://*.ingest.de.sentry.io https://upload.uploadcare.com` | API REST + SSE Mercure + ingest Sentry (US et EU couverts) + endpoint upload direct Uploadcare. |
| `frame-ancestors` | `'none'` | Anti-clickjacking : aucun site ne peut iframer l'app. |
| `base-uri` | `'self'` | Empêche un attaquant injecté de réécrire `<base>` pour rediriger les liens relatifs. |
| `form-action` | `'self'` | Les `<form>` ne peuvent poster qu'au même origin. |

**Headers complémentaires** : `X-Content-Type-Options: nosniff`,
`X-Frame-Options: DENY`, `Referrer-Policy: strict-origin-when-cross-origin`,
`Permissions-Policy: geolocation=(), camera=(), microphone=(), payment=()`.

> ⚠️ **Recommandation Massamba** : tester la CSP **en preview Vercel
> avant de promouvoir en prod**. Ouvrir la console DevTools sur le
> preview deploy, naviguer dans les écrans clés (login, dashboard,
> planning, facturation, housekeeping), vérifier qu'aucun warning
> `Refused to … because it violates the following Content Security
> Policy directive` n'apparaît. Si un domaine légitime est bloqué,
> ajuster la directive correspondante dans `vercel.json` AVANT le
> go-live. Une CSP trop stricte peut blanc-écran la SPA — la pose en
> preview est filet de sécurité.

---

## 8. DNS Cloudflare

Domaine `getstayos.com` géré par Cloudflare. À configurer :

| Type | Nom | Cible | Proxy (orange) |
|---|---|---|---|
| CNAME | `demo` | `<vercel-target>.vercel-dns.com` | ✅ Proxy ON |
| CNAME | `api` | `<heroku-dns-target>` pour `stayos-api` | ✅ Proxy ON |
| CNAME | `mercure` | `<heroku-dns-target>` pour `stayos-mercure` | ⚠️ Voir ci-dessous |
| CNAME | `*` | `<vercel-target>.vercel-dns.com` (résolution tenant front) | ✅ Proxy ON |
| CNAME | `superadmin` | (selon archi : Vercel ou backend) | ✅ Proxy ON |

**Récupérer la cible Heroku** :
```bash
heroku domains:add api.getstayos.com -a stayos-api
heroku domains:add mercure.getstayos.com -a stayos-mercure
heroku domains -a stayos-api      # affiche le DNS target à poser dans Cloudflare
heroku domains -a stayos-mercure
```

### 8.1 ⚠️ SSE Mercure + proxy Cloudflare

Le proxy Cloudflare (orange cloud) **peut bufferiser les
réponses SSE** longues, ce qui casse les notifications temps
réel. Deux options :

- **Recommandé** : passer `mercure.getstayos.com` en **DNS-only
  (grey cloud)** — Cloudflare ne s'interpose plus, le SSE
  fonctionne nativement avec le TLS Heroku.
- Alternative : garder proxy ON et activer la règle Cloudflare
  « disable buffering » sur la route `/.well-known/mercure*`
  (plan payant nécessaire selon le compte).

Le `api.` et le `demo.` peuvent rester en proxy ON sans
problème (HTTP request/response classique).

### 8.2 Wildcard tenants

`*.getstayos.com` pointe sur Vercel : c'est le frontend qui
extrait le slug tenant depuis `window.location.hostname` (cf.
`frontend/src/services/tenant.ts`, fallback
`VITE_DEFAULT_TENANT_SLUG`) et inclut le slug dans les appels
API via header / subdomain. **L'API reste sur `api.` unique**,
elle reçoit le slug via le header (ou la query) et le résout
serveur-side (TenantMiddleware).

---

## 9. Monitoring

### 9.1 Sentry

- **Backend** : DSN câblé via `SENTRY_DSN` (config
  `backend/config/packages/sentry.yaml`, livré Sprint 14-B.1.1).
  Poser la valeur en Config Var Heroku → smoke test en
  déclenchant volontairement une 500 (ex : route protégée
  appelée sans token) et vérifier la réception côté Sentry.
- **Frontend** : `@sentry/vue` intégré dans `frontend/src/main.ts`
  (Sprint 14-C). Init **conditionnelle** sur `VITE_SENTRY_DSN` :
  vide → SDK silencieux (dev). Côté Vercel, poser `VITE_SENTRY_DSN`
  pointant sur le projet Sentry frontend séparé du backend (2 projets
  recommandés, cf. §0). Config V1 minimale : `environment` dérivé
  de `import.meta.env.MODE`, `tracesSampleRate: 0.1`, pas
  d'integrations supplémentaires. Smoke test : lever volontairement
  une exception Vue en preview → vérifier la réception côté Sentry.

### 9.2 UptimeRobot

Créer 3 monitors HTTP(S) (intervalle 5 min, alertes email/SMS) :

| Nom | URL | Réponse attendue |
|---|---|---|
| StayOS API | `https://api.getstayos.com/api/health` | 200 + JSON `{"status":"ok",...}` |
| StayOS Frontend | `https://demo.getstayos.com/` | 200 + HTML |
| StayOS Mercure | `https://mercure.getstayos.com/.well-known/mercure?topic=test` | 200 (réponse SSE — adapter selon hub) |

Activer ensuite la **status page publique** UptimeRobot →
URL type `https://status.getstayos.com` (CNAME Cloudflare
vers la page UptimeRobot).

### 9.3 Logs centralisés

Ajouter l'add-on Papertrail :
```bash
heroku addons:create papertrail:choklad -a stayos-api
heroku addons:create papertrail:choklad -a stayos-mercure
```

Configurer les 4 alertes Papertrail mentionnées dans
`logging.md` (login_failed > seuil, paydunya error, webhook
invalid sig, level:critical).

---

## 10. Limitations connues prod

- **SSE Mercure + Heroku** : Heroku router applique un timeout
  HTTP de 30 s à l'établissement de connexion et 55 s pour
  une requête sans byte envoyé, puis maintient les
  connexions longues tant que des bytes circulent. Les dynos
  redémarrent par ailleurs **automatiquement toutes les ~24h**
  (cycling). → reconnexion gérée par
  `frontend/src/services/mercure.service.ts` (`ensureToken`
  async + retry EventSource natif). Acceptable pour la démo
  ; à surveiller en production réelle.
- **`LOCK_DSN=flock`** : le lock est local au dyno. Tant que
  le worker Messenger tourne sur un **seul** dyno worker, c'est
  cohérent. Si l'on passe à plusieurs workers en parallèle,
  basculer vers un lock Redis (`redis+lock://...`).
- **Heroku Hobby dynos** : pas de sleep (contrairement à
  Eco/Free) mais 1 instance unique → indisponibilité brève
  pendant un cycling/redeploy. Pour une vraie prod multi-AZ,
  passer en Standard-2X.
- **`generateNextNumber`** (factures SaaS) : `COUNT(*)` non
  transactionnel, race condition théorique. Acceptable
  jusqu'à plusieurs workers en parallèle (voir leçons
  d'architecture).

---

## 11. Ordre des opérations — checklist séquentielle

À cocher dans l'ordre. Aucune étape ne peut être sautée
sans casser une étape ultérieure.

**Préparation**
- [ ] §2 — Générer les 3 secrets (APP_SECRET, JWT_PASSPHRASE,
  MERCURE_JWT_SECRET), les stocker dans le gestionnaire
- [ ] §2.1 — Régénérer la paire de clés JWT avec la nouvelle
  passphrase, encoder en base64 (1 ligne), stocker dans le
  gestionnaire — voir le mode opératoire §2.1 mis à jour
- [x] §5 — Option image backend tranchée : **B (FrankenPHP)**,
  fichiers produits (Sprint 14-C étape 2) :
  `backend/docker/php/Dockerfile.prod`, `backend/docker/php/Caddyfile`,
  `backend/heroku.yml`, `backend/.dockerignore`,
  `ops/mercure/Dockerfile`, `ops/mercure/heroku.yml`,
  `ops/mercure/README.md`.
- [x] **Sprint 14-C.1 — Stratégie clés JWT prod (b)
  IMPLÉMENTÉE** : override `when@prod` dans
  `backend/config/packages/lexik_jwt_authentication.yaml` lit
  `JWT_SECRET_KEY_B64` / `JWT_PUBLIC_KEY_B64` via processeur
  `base64:`. Stubs build-time scopés au `RUN cache:warmup`
  (cf. `Dockerfile.prod`). Mode opératoire complet §2.1.

**Apps Heroku — backend (`stayos-api`)**
- [ ] `heroku create stayos-api --region eu` (région la plus
  proche de Dakar pour latence acceptable)
- [ ] `heroku stack:set container -a stayos-api`
- [ ] `heroku addons:create heroku-postgresql:essential-0 -a stayos-api`
- [ ] `heroku addons:create heroku-redis:mini -a stayos-api`
- [ ] `heroku addons:create papertrail:choklad -a stayos-api`
- [ ] Poser TOUTES les Config Vars du §3 via `heroku config:set`
- [ ] Vérifier `DATABASE_URL` (schéma `postgresql://`, sslmode,
  serverVersion) — §3.1
- [ ] Configurer le remote git Heroku :
  ```bash
  heroku git:remote -a stayos-api -r heroku-api
  ```
- [ ] **Subtree push** (build context = `backend/`) :
  ```bash
  git subtree push --prefix=backend heroku-api main
  ```
  Heroku build l'image via `backend/heroku.yml`, déclenche la
  release phase (migrations global + tenant + cache:clear) puis
  bascule le trafic.
- [ ] Vérifier les logs : `heroku logs --tail -a stayos-api`.
  Attendu : release phase OK, web dyno + worker dyno UP.
- [ ] Smoke test : `curl https://api.getstayos.com/api/health`
  → 200 + `{"status":"ok", …}`.

**Apps Heroku — Mercure (`stayos-mercure`)**
- [ ] `heroku create stayos-mercure --region eu`
- [ ] `heroku stack:set container -a stayos-mercure`
- [ ] Poser les Config Vars du §4 (notamment
  `MERCURE_PUBLISHER_JWT_KEY` = `MERCURE_SUBSCRIBER_JWT_KEY` =
  `MERCURE_JWT_SECRET` du backend — invariant strict)
- [ ] Configurer le remote git :
  ```bash
  heroku git:remote -a stayos-mercure -r heroku-mercure
  ```
- [ ] **Subtree push** :
  ```bash
  git subtree push --prefix=ops/mercure heroku-mercure main
  ```
- [ ] Smoke test :
  ```bash
  curl -i 'https://mercure.getstayos.com/.well-known/mercure?topic=test'
  ```
  Attendu : **401 Unauthorized** (preuve que le hub répond et
  qu'`anonymous` est bien désactivé).

**Provisionnement du tenant démo**
- [ ] `heroku run -a stayos-api php bin/console stayos:tenant:provision demo`
- [ ] `heroku run -a stayos-api php bin/console stayos:tenant:ensure-hotel-profile`
  (filet de sécurité, idempotent)

**Frontend Vercel**
- [ ] Poser les variables d'env Vercel (§7.1)
- [ ] Réactiver les builds (toggle production branch = `main`)
- [ ] Trigger un déploiement manuel pour valider
- [ ] Ajouter le domaine `demo.getstayos.com`, récupérer la
  cible CNAME

**DNS Cloudflare**
- [ ] Créer les 5 enregistrements DNS du §8
- [ ] **Passer `mercure.` en DNS-only (grey cloud)** — §8.1
- [ ] Attendre la propagation (en général < 5 min sur
  Cloudflare)

**Monitoring**
- [ ] Smoke test Sentry backend (déclencher 500 volontaire,
  vérifier réception)
- [ ] Créer les 3 monitors UptimeRobot (§9.2)
- [ ] Activer la status page UptimeRobot, pointer
  `status.getstayos.com` dessus
- [ ] Configurer les 4 alertes Papertrail (cf. `logging.md`)

**Smoke tests fonctionnels sur `demo.getstayos.com`**
- [ ] Login staff (créé par la commande de provision)
- [ ] Créer une réservation
- [ ] Émettre une facture
- [ ] Tester un checkout Paydunya (sandbox si `PAYDUNYA_MODE=test`)
- [ ] Vérifier qu'une notification Mercure arrive en temps
  réel (changer le statut d'une chambre dans un autre
  onglet)
- [ ] Vérifier qu'un email Mailjet est reçu (confirmation
  réservation)

**Go-live**
- [ ] Bump `APP_VERSION` (tag git + Config Var)
- [ ] Annoncer le go-live, surveiller Sentry + Papertrail
  pendant 24h
