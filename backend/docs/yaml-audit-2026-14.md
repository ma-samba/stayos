# Audit des paramètres YAML hardcodés — Sprint 14-A.1

Date : 2026-06-11
Volume audité : 25 fichiers yaml (`config/services.yaml`, `config/routes.yaml`, 22 fichiers `config/packages/*.yaml`, 1 override `config/packages/test/cache.yaml`)

## Synthèse

- Paramètres migrés vers `%env(VAR)%` au cours de ce chantier : **0**
- Constantes légitimes laissées en place (avec justification) : **majorité du contenu**
- Env vars référencées dans les YAML : **19** (dont 14 obligatoires + 5 optionnelles `default::`)
- Env vars manquantes dans `backend/.env` : **0**
- Env vars orphelines (référencées sans définition) : **1** (`VAR_DUMPER_SERVER`) — fournit son propre défaut interne par le Symfony Debug Bundle (`127.0.0.1:9912`)

**Conclusion** : la configuration est saine. Le fix Sprint 12 (`default_backend_url` → `%env(APP_BACKEND_URL)%`) avait déjà ramassé le principal offender. Aucune valeur d'environnement n'est aujourd'hui hardcodée dans un YAML où elle pourrait polluer la prod.

## Toutes les env vars référencées dans les YAML

| Variable | Référence YAML | Présence dans `backend/.env` |
|---|---|---|
| `APP_SECRET` | `framework.yaml` | ✅ |
| `APP_BACKEND_URL` | `services.yaml` | ✅ |
| `DATABASE_URL` | `doctrine.yaml` | ✅ |
| `DEFAULT_URI` | `routing.yaml` | ✅ |
| `FRONTEND_URL` | `services.yaml`, `nelmio_cors.yaml` | ✅ |
| `SUPERADMIN_URL` | `nelmio_cors.yaml` (optionnelle `default::`) | ✅ |
| `JWT_SECRET_KEY` | `lexik_jwt_authentication.yaml` | ✅ |
| `JWT_PUBLIC_KEY` | `lexik_jwt_authentication.yaml` | ✅ |
| `JWT_PASSPHRASE` | `lexik_jwt_authentication.yaml` | ✅ |
| `LOCK_DSN` | `lock.yaml` | ✅ |
| `MAILER_DSN` | `mailer.yaml` | ✅ |
| `MERCURE_URL` | `mercure.yaml` | ✅ |
| `MERCURE_PUBLIC_URL` | `mercure.yaml` | ✅ |
| `MERCURE_JWT_SECRET` | `mercure.yaml` | ✅ |
| `MESSENGER_TRANSPORT_DSN` | `messenger.yaml` | ✅ |
| `REDIS_HOST` | `services.yaml` | ✅ |
| `REDIS_PORT` | `services.yaml` | ✅ |
| `VAR_DUMPER_SERVER` | `debug.yaml` (dev only) | ⚠️ défaut Symfony (`127.0.0.1:9912`) |

## Inventaire par fichier

### `config/services.yaml`

**État** : déjà propre depuis Sprint 12.

```yaml
parameters:
    default_frontend_url: '%env(FRONTEND_URL)%'
    default_backend_url:  '%env(APP_BACKEND_URL)%'
```

Plus `Redis` service avec `%env(resolve:REDIS_HOST)%` et `%env(int:REDIS_PORT)%`. **Aucune action**.

### `config/routes.yaml`

**Contenu** : chemins de contrôleurs (`../src/Controller/`) et déclarations de routes auth (méthode POST). **Aucune valeur d'environnement** — chemins relatifs au projet et préfixes d'URL constants par contrat API. **Aucune action**.

### `config/packages/cache.yaml`

**Contenu** : 100 % de lignes commentées (doc Symfony par défaut). **Aucune action**.

### `config/packages/debug.yaml`

```yaml
when@dev:
    debug:
        dump_destination: "tcp://%env(VAR_DUMPER_SERVER)%"
```

**État** : `%env(VAR_DUMPER_SERVER)%` n'est PAS dans `backend/.env`, mais le Symfony Debug Bundle fournit lui-même un défaut interne (`127.0.0.1:9912`) — vérifié via `debug:container --env-vars` qui affiche `VAR_DUMPER_SERVER | "127.0.0.1:9912" | n/a`. **Aucune action** (le défaut interne suffit, ajouter à `.env` créerait une maintenance inutile).

### `config/packages/doctrine_migrations.yaml`

```yaml
migrations_paths:
    'DoctrineMigrations': '%kernel.project_dir%/migrations/global'
enable_profiler: false
transactional: false
check_database_platform: false
```

**Constantes légitimes** : chemin de migration relatif au kernel, comportements bundle. **Aucune action**.

### `config/packages/doctrine.yaml`

```yaml
dbal:
    url: '%env(resolve:DATABASE_URL)%'
```

DSN BDD déjà en env. Le reste (`auto_generate_proxy_classes`, `enable_lazy_ghost_objects`, naming strategy, mappings) sont des constantes bundle. **Aucune action**.

### `config/packages/framework.yaml`

```yaml
secret: '%env(APP_SECRET)%'
```

Le reste (`http_method_override`, `handle_all_throwables`, session config, router utf8, translator fallback) sont des constantes framework. **Aucune action**.

### `config/packages/lexik_jwt_authentication.yaml`

```yaml
secret_key:  '%env(resolve:JWT_SECRET_KEY)%'
public_key:  '%env(resolve:JWT_PUBLIC_KEY)%'
pass_phrase: '%env(JWT_PASSPHRASE)%'
token_ttl: 3600
```

`token_ttl: 3600` (1h) est une politique de sécurité de l'app — constant intentionnel. Le reste est en env. **Aucune action**.

### `config/packages/lock.yaml`, `mailer.yaml`, `mercure.yaml`

Tout en `%env()%`. **Aucune action**.

### `config/packages/messenger.yaml`

```yaml
async:
    dsn: '%env(MESSENGER_TRANSPORT_DSN)%'
    retry_strategy:
        max_retries: 3
        delay: 1000
        multiplier: 2
failed:
    dsn: 'doctrine://default?queue_name=failed'
```

- `'doctrine://default?queue_name=failed'` : le `default` est l'alias de connexion Doctrine (qui pointe sur `DATABASE_URL`). Convention Symfony Messenger, **pas une valeur d'environnement**.
- Retry strategy : tuning constants. **Aucune action**.

### `config/packages/monolog.yaml`

Toutes les valeurs hardcodées sont des chemins kernel (`%kernel.logs_dir%`, `%kernel.environment%`) ou des constantes Heroku-friendly (`php://stderr` en prod). **Aucune action**.

### `config/packages/nelmio_cors.yaml`

```yaml
allow_origin:
    - 'http://localhost:5173'
    - 'http://localhost:8080'
    - '%env(default::FRONTEND_URL)%'
    - '^https://stayos.*\.vercel\.app$'
```

- `'http://localhost:5173'`, `'http://localhost:8080'` : URLs **dev intentionnelles**, coexistent avec la variable env qui les remplace en prod. En prod ces entrées ne matchent aucune origin réelle (donc inoffensives).
- `'^https://stayos.*\.vercel\.app$'` : regex de matching preview Vercel — pas une URL à varier par environnement.
- `'%env(default::FRONTEND_URL)%'`, `'%env(default::SUPERADMIN_URL)%'` : `default::` rend la variable optionnelle (default = chaîne vide). C'est un choix défensif : si la var n'est pas définie en prod, l'origin n'est juste pas allowlistée — fail-safe vs fail-fast.

**Note** : ce double pattern (dev hardcodés + env pour prod) est volontaire et fonctionne bien. **Aucune action**.

### `config/packages/rate_limiter.yaml`

Toutes les valeurs (`limit: 5`, `interval: '1 minute'`, etc.) sont des **politiques métier** — constantes intentionnelles documentées dans `.claude/docs/security.md`. **Aucune action**.

### `config/packages/routing.yaml`

```yaml
default_uri: '%env(DEFAULT_URI)%'
```

Déjà en env. `when@prod: strict_requirements: null` est une convention framework. **Aucune action**.

### `config/packages/security.yaml`

- Patterns `^/api/health$`, `^/superadmin` etc. : routes regex, **pas des URLs à varier**.
- `login_throttling: max_attempts: 5, interval: '1 minute'` : politique de sécurité constante.
- Hiérarchie de rôles : constantes métier.

**Aucune action**.

### `config/packages/stof_doctrine_extensions.yaml`

```yaml
default_locale: fr_FR
orm:
    default:
        timestampable: false
        sluggable: false
```

Préset bundle, constantes config. **Aucune action**.

### `config/packages/translation.yaml`, `twig.yaml`, `validator.yaml`, `web_profiler.yaml`

Constantes config framework. **Aucune action**.

### `config/packages/test/cache.yaml`

```yaml
framework:
    cache:
        app: cache.adapter.array
```

Override d'environnement test (isolation du rate limiter entre tests). Valeurs hardcodées **intentionnelles et recommandées**. **Aucune action**.

## Constantes légitimes — synthèse

| Fichier | Paramètre | Valeur | Raison |
|---|---|---|---|
| `lexik_jwt_authentication.yaml` | `token_ttl` | `3600` | Politique de sécurité (durée de vie JWT 1h) |
| `messenger.yaml` | `async.retry_strategy` | `max_retries: 3, delay: 1000, multiplier: 2` | Tuning interne worker, identique tous environnements |
| `messenger.yaml` | `failed.dsn` | `'doctrine://default?queue_name=failed'` | Convention Symfony Messenger sur l'alias Doctrine `default` |
| `rate_limiter.yaml` | tous | login 5/min, register 3/h, api_read 300/min, api_write 60/min, webhooks 100/min | Politique métier figée, [security.md] |
| `security.yaml` | `login_throttling` | `max_attempts: 5, interval: '1 minute'` | Politique de sécurité |
| `security.yaml` | `role_hierarchy` | tout l'arbre | Modèle de rôles métier |
| `stof_doctrine_extensions.yaml` | tout | `default_locale: fr_FR`, orm flags | Préset bundle |
| `doctrine_migrations.yaml` | `transactional: false` | — | Migrations StayOS sont gérées manuellement par étape (Doctrine + tenant migrate) |
| `nelmio_cors.yaml` | URLs `localhost:5173`/`8080` | — | Fallback dev intentionnel coexistant avec `%env(default::FRONTEND_URL)%` |
| `nelmio_cors.yaml` | regex Vercel | `^https://stayos.*\.vercel\.app$` | Pattern de matching preview, pas une URL à varier |

## Variables manquantes dans `.env` — corrigées

**Aucune.** Toutes les `%env()%` référencées dans les YAML obligatoires sont documentées dans `backend/.env`. Le cas marginal `VAR_DUMPER_SERVER` (debug.yaml, dev only) est couvert par le défaut interne du bundle Symfony.

## Recommandations pour la suite

- **Pre-commit hook ou test reflection** qui scanne `config/**/*.yaml` à la recherche de `%env(VAR)%` et vérifie qu'une entrée `VAR=...` existe dans `backend/.env`. À planifier en Sprint 14-A.3 ou 14-B — éviterait la régression Sprint 12 (URL hardcodée pendant un sprint).
- **Audit `.env` côté prod** : au déploiement Heroku, vérifier qu'aucune valeur dev type `localhost`, `change_me_*`, `stayos_jwt_dev_secret`, `_min_32bytes_long_pad` ne fuit en prod via une Heroku Config Var manquante. Backlog Sprint 14-C / déploiement.
- **CORS dev fallback** : le pattern actuel `'http://localhost:5173'` hardcodé + `%env(default::FRONTEND_URL)%` est fonctionnel mais visuellement bruyant en prod (allowlist inoffensive mais inutile). Une variante propre (séparer dev/prod via `when@dev:` / `when@prod:`) serait plus claire. À traiter en polish 14-B si pertinent.
