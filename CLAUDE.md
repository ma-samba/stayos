# StayOS — Contexte projet

SaaS de gestion hôtelière (PMS) pour les établissements d'Afrique de l'Ouest.
Backend Symfony 7 (API REST) + Frontend Vue.js 3.

## Stack
- **Backend** : PHP 8.3, Symfony 7, Doctrine ORM, PostgreSQL 16, Redis
- **Frontend** : Vue.js 3, Pinia, TypeScript, Tailwind CSS, vue-i18n, Vite
- **Auth** : JWT (LexikJWTAuthenticationBundle) + Refresh Token + OTP
- **Temps réel** : Mercure (SSE)
- **Queue** : Symfony Messenger + Redis
- **Dev** : Docker Compose, Make

## Services externes
- **Paydunya** : passerelle de paiement (Wave, Orange Money, cartes)
- **Uploadcare** : CDN images (logos hôtels, photos chambres, documents clients)
- **Mailjet** : emails transactionnels (OTP, confirmations, factures)
- **Amazon RDS** : PostgreSQL 16 managé en production
- **Sentry** : supervision des erreurs
- **Papertrail** : agrégation des logs Heroku
- **UptimeRobot** : monitoring uptime

## Infrastructure
```
Dev local  → Docker Compose (PHP + Nginx + PostgreSQL + Redis + Mercure + Mailpit)
Backend    → Heroku (buildpack PHP, Procfile)
Frontend   → Vercel (Vue.js, vercel.json)
BDD prod   → Amazon RDS PostgreSQL 16
Cache prod → Heroku Redis addon
```

## Structure
```
stayos/
├── backend/src/
│   ├── Platform/            # SaaS (Tenant, Subscription, Billing, Onboarding, Auth)
│   ├── Hotel/               # Métier (Room, Reservation, Guest, Billing, Housekeeping...)
│   ├── Shared/              # TenantContext, TenantAwareRepository, Exceptions, Security
│   ├── Controller/Api/
│   ├── DTO/
│   └── DataFixtures/
├── frontend/src/
│   ├── modules/             # Découpage par domaine métier
│   ├── shared/              # UI, composables, utils
│   ├── stores/              # Pinia
│   ├── i18n/                # Traductions FR/EN
│   ├── router/
│   └── services/            # Axios, Uploadcare, Mercure
└── docker/
```

## Commandes essentielles
```bash
make start              # Démarrer Docker
make shell              # Shell PHP
make migrate            # Migrations Doctrine
make fixtures           # Recharger fixtures
make cache              # Vider cache Symfony
make test               # Lancer les tests
make test-security      # Tests isolation multi-tenant uniquement
docker compose logs -f  # Logs
```

## Architecture multi-tenant — CRITIQUE
StayOS utilise **PostgreSQL schemas** :
- Schema `public` → données globales (tenants, plans, subscriptions)
- Schema `hotel_{uuid}` → données métier de chaque hôtel

Le `TenantMiddleware` identifie le tenant via le **subdomain** et exécute
`SET search_path TO hotel_{uuid}, public` à chaque requête.
**Jamais de filtrage par tenant_id** — le search_path s'en charge.

## Règles IMPORTANTES
- Montants en **XOF (FCFA)**, stockés en `DECIMAL(10,2)`, type PHP `string`
- Timezone : **Africa/Dakar**
- Statuts → **enums PHP 8.1**
- Controllers → **JSON uniquement**, logique métier dans les **Services**
- Chaque écriture → **DTO** + validation
- Uploads → **Uploadcare** (jamais de stockage local)
- Emails → **Mailjet** en prod (Mailpit en dev)
- Paiements → **Paydunya** uniquement
- Toutes les chaînes UI → **vue-i18n** (`$t()`)
- Audit log sur toutes les actions sensibles
- Rate limiting sur tous les endpoints publics

## Format réponse API standard
```json
{ "data": {}, "message": "OK", "status": 200 }
```
Erreur :
```json
{ "error": "Message", "code": "ERROR_CODE", "status": 400 }
```

## Codes d'erreur standards
| Code | Status |
|---|---|
| `VALIDATION_ERROR` | 422 |
| `NOT_FOUND` | 404 |
| `ACCESS_DENIED` | 403 |
| `PLAN_LIMIT` | 403 |
| `CONFLICT` | 409 |
| `RATE_LIMITED` | 429 |
| `TENANT_SUSPENDED` | 402 |
| `PAYMENT_FAILED` | 402 |
| `EXTERNAL_SERVICE_ERROR` | 503 |

## Références détaillées (charger selon besoin)
- Entités & BDD → @.claude/docs/entities.md
- Architecture API → @.claude/docs/api.md
- Services métier → @.claude/docs/services.md
- Services externes → @.claude/docs/external-services.md
- Sécurité → @.claude/docs/security.md
- Tests → @.claude/docs/testing.md
- Logs & Supervision → @.claude/docs/logging.md
- Gestion des erreurs → @.claude/docs/errors.md
- Internationalisation → @.claude/docs/i18n.md
- Design System → @.claude/docs/design-system.md
- Fixtures → @.claude/docs/fixtures.md
- Déploiement → @.claude/docs/deploy.md

## Règle design (frontend)
Charger @.claude/docs/design-system.md avant tout composant Vue.
Tokens CSS `--pms-*` uniquement. Tabler Icons outline. Jamais de hex en dur.

## Règle sécurité
Charger @.claude/docs/security.md avant tout endpoint auth, webhook ou action sensible.
Toujours : vérifier signature webhook, appliquer rate limiter, logger dans le bon channel.

## Règle tests
Tout nouveau Service → test unitaire dans tests/Unit/Service/.
Tout nouvel endpoint → test fonctionnel dans tests/Functional/Api/.
Toute modification multi-tenant → relancer make test-security.
