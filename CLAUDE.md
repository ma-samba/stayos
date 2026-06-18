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
Backend    → Heroku container stack (FrankenPHP, heroku.yml)
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
make migrate-tenant-dry # Voir les migrations tenant en attente (dry-run)
make migrate-tenant-all # Appliquer migrations sur tous les schemas tenant
docker compose logs -f  # Logs
```

## Architecture multi-tenant — CRITIQUE
StayOS utilise **PostgreSQL schemas** :
- Schema `public` → données globales (tenants, plans, subscriptions)
- Schema `hotel_{uuid}` → données métier de chaque hôtel

Le `TenantMiddleware` identifie le tenant via le **subdomain** et exécute
`SET search_path TO hotel_{uuid}, public` à chaque requête.
**Jamais de filtrage par tenant_id** — le search_path s'en charge.

### Migrations tenant — process en 2 temps OBLIGATOIRE
Les migrations Doctrine standard (`make migrate`) ne touchent que le schema `public`.
Pour propager un changement de schéma aux tables tenant (`hotel_{uuid}`), il faut
**toujours** exécuter les deux étapes :
```bash
make migrate                # 1. Génère + applique la migration sur public
make migrate-tenant-all     # 2. Applique sur TOUS les schemas hotel_{uuid}
```
Oublier l'étape 2 = les hôtels existants n'ont pas la nouvelle colonne/table
→ erreurs Doctrine en production.
Vérifier d'abord avec `make migrate-tenant-dry` (dry-run) avant d'appliquer.

#### Enums protégés par une contrainte CHECK SQL
Plusieurs colonnes-enum (`rooms.status`, `reservations.status`,
`reservations.source`, `invoices.status`, `payments.method`,
`cleaning_tasks.status/type`) ont une contrainte CHECK SQL qui liste
les valeurs autorisées (définie dans `CreateHotelTables`).

⚠️ Ajouter une valeur à un enum PHP **NE SUFFIT PAS** si la colonne a une
contrainte CHECK : l'INSERT sera rejeté en **SQLSTATE 23514** (500).
Il faut une migration tenant qui **RECRÉE** la contrainte :
```sql
ALTER TABLE {table} DROP CONSTRAINT IF EXISTS {table}_{column}_check;
ALTER TABLE {table} ADD CONSTRAINT {table}_{column}_check
  CHECK ({column} IN ('valeur1','valeur2',...,'nouvelle_valeur'));
```
(PostgreSQL nomme les CHECK inline `{table}_{column}_check`.)
Puis enregistrer la migration dans `TenantMigrationRegistry` et lancer
`make migrate-tenant-all`. Le symptôme d'un oubli : 500 SQLSTATE 23514
"violates check constraint" à l'écriture, alors que l'enum PHP semble
correct.

## Règles IMPORTANTES
- Montants en **XOF (FCFA)**, stockés en `DECIMAL(10,2)`, type PHP `string`
- Timezone : **Africa/Dakar**
- Statuts → **enums PHP 8.1**. Si la colonne a une contrainte CHECK
  SQL, toute nouvelle valeur d'enum exige une migration tenant qui
  recrée la contrainte (voir "Enums protégés par une contrainte CHECK").
- Controllers → **JSON uniquement**, logique métier dans les **Services**
- Règle métier violée → lever **`App\Shared\Exception\BusinessRuleException`**
  (rendu en **422 `BUSINESS_RULE`** par ApiExceptionListener).
  **JAMAIS** de `\LogicException`, `\RuntimeException` ou `\Exception`
  brute dans les Services : le listener ne les catche plus et elles
  tombent en 500 "erreur interne" (message cryptique pour l'utilisateur).
  Conflits de ressource (doublon, chevauchement) → `ConflictException` (409).
  Introuvable → `NotFoundException`/404. Validation DTO → 422 automatique.
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
| `BUSINESS_RULE` | 422 |
| `NOT_FOUND` | 404 |
| `ACCESS_DENIED` | 403 |
| `PLAN_LIMIT` | 403 |
| `ALREADY_EXISTS` | 409 |
| `CONFLICT` | 409 |
| `RATE_LIMITED` | 429 |
| `TENANT_SUSPENDED` | 402 |
| `INTERNAL_ERROR` | 500 |

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
Toujours : appliquer le rate limiter, logger dans le bon channel, auditer les actions sensibles.
Webhooks de paiement (Paydunya) : Paydunya ne signe pas ses IPN.
Sécuriser par (1) token secret dans l'URL de callback (filtre d'entrée)
ET (2) reconfirmation serveur du paiement via l'API (source de vérité),
JAMAIS faire confiance au seul payload reçu.

## Règle tests
Tout nouveau Service → test unitaire dans tests/Unit/Service/.
Tout nouvel endpoint → test fonctionnel dans tests/Functional/Api/.
Toute modification multi-tenant → relancer make test-security.
`make test-setup` ne migre que le schema `public` sur `stayos_test`.
Après un ajout de migration tenant → lancer manuellement
`make migrate-tenant-all` sur la BDD de test (ou `db-reset` + `test-setup`).
