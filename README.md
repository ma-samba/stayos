# 🏨 StayOS PMS

Logiciel de gestion hôtelière SaaS (PMS) pour les établissements d'Afrique de l'Ouest.
Multi-tenant par PostgreSQL schemas · Symfony 7 · Vue.js 3

## Stack technique

| Composant | Technologie |
|---|---|
| Backend | Symfony 7 + API REST |
| Base de données | Amazon RDS PostgreSQL 16 (multi-schema) |
| Auth | JWT (LexikJWT) |
| Temps réel | Mercure (SSE) |
| Frontend | Vue.js 3 + Pinia + TypeScript + Tailwind |
| Cache / Queue | Redis + Symfony Messenger |
| Emails | Mailjet (Mailpit en dev) |
| Paiement | Paydunya (Wave, Orange Money, cartes) |
| Images | Uploadcare CDN |
| Backend prod | Heroku |
| Frontend prod | Vercel |
| BDD prod | Amazon RDS PostgreSQL 16 |

---

## Prérequis

- [Docker Desktop](https://www.docker.com/products/docker-desktop/) ≥ 24
- `make` (Linux/macOS natif — [Git Bash](https://gitforwindows.org/) sur Windows)

---

## Installation locale (1ère fois)

```bash
# 1. Cloner le projet
git clone https://github.com/votre-repo/stayos.git
cd stayos

# 2. Lancer le script d'installation
bash setup.sh

# OU avec Make
make install
```

Le script va automatiquement :
- Créer `.env.local` et `frontend/.env.local` depuis les templates
- Builder les images Docker
- Installer Composer et npm
- Générer les clés JWT
- Créer la base de données et exécuter les migrations
- Charger les données de démonstration (2 hôtels)
- Provisionner les schemas PostgreSQL des tenants de démo

> ⚠️ Les clés Paydunya et Uploadcare sont optionnelles pour démarrer.
> Les emails sont interceptés par Mailpit (http://localhost:8025) en dev.

---

## URLs locales

| Service | URL | Notes |
|---|---|---|
| API Symfony | http://localhost:8080/api | |
| Frontend Vue.js | http://localhost:5173 | |
| Mailpit | http://localhost:8025 | Tous les emails atterrissent ici |
| Mercure SSE | http://localhost:9090 | |
| PostgreSQL | localhost:5432 | |
| Redis | localhost:6379 | |

### Subdomains locaux (/etc/hosts)
```
127.0.0.1  savana.localhost
127.0.0.1  villa-collines.localhost
127.0.0.1  superadmin.localhost
```

---

## Commandes courantes

```bash
make start              # Démarrer les conteneurs
make stop               # Arrêter
make logs               # Voir les logs
make shell              # Shell PHP
make shell-db           # Console PostgreSQL
make migrate            # Créer et exécuter les migrations
make fixtures           # Recharger les données de démo
make cache              # Vider le cache Symfony
make test               # Tests PHPUnit
make tenant-provision   # Provisionner un tenant (SLUG=mon-hotel)
```

---

## Comptes de démonstration

### Hôtel Savana Dakar — Plan Pro
| Rôle | Email | Password |
|---|---|---|
| Manager | admin@savana-hotel.sn | admin123 |
| Réceptionniste | reception@savana-hotel.sn | recep123 |

### Villa Collines Saly — Plan Starter
| Rôle | Email | Password |
|---|---|---|
| Manager | admin@villa-collines.sn | admin123 |

### Super Admin plateforme
| Email | Password |
|---|---|
| superadmin@stayos.sn | superadmin123 |

---

## Services externes

| Service | Usage | Config |
|---|---|---|
| **Paydunya** | Paiements (Wave, OM, cartes) | `PAYDUNYA_*` dans `.env.local` |
| **Uploadcare** | CDN images et documents | `UPLOADCARE_*` dans `.env.local` |
| **Mailjet** | Emails prod (Mailpit en dev) | `MAILJET_*` dans `.env.local` |
| **Amazon RDS** | PostgreSQL prod | `DATABASE_URL` en prod |

Voir `.claude/docs/external-services.md` pour la doc complète de chaque service.

---

## Déploiement

```bash
# Backend → Heroku
cd backend/
git push heroku main

# Frontend → Vercel
cd frontend/
vercel --prod
```

Voir `.claude/docs/deploy.md` pour le guide complet (setup Heroku, RDS, Vercel, domaines).

---

## Structure du projet

```
stayos/
├── backend/                   # Symfony 7
│   ├── heroku.yml             # Heroku container stack : web + worker + release
│   ├── docker/php/            # Dockerfile.prod (FrankenPHP) + Caddyfile
│   ├── config/packages/       # JWT, CORS, Messenger, Mercure...
│   └── src/
│       ├── Platform/          # SaaS (Tenant, Plan, Subscription...)
│       ├── Hotel/             # Métier (Room, Reservation, Guest...)
│       └── Shared/            # TenantContext, Services externes...
│
├── frontend/                  # Vue.js 3 + TypeScript
│   ├── vercel.json            # Config déploiement Vercel
│   └── src/
│       ├── modules/           # Dashboard, Rooms, Reservations...
│       ├── shared/            # UI, composables, utils
│       └── services/          # Axios, Uploadcare, Mercure
│
├── docker/                    # Config Docker (dev local uniquement)
│
├── .claude/docs/              # Documentation Claude Code
│   ├── entities.md
│   ├── api.md
│   ├── services.md
│   ├── external-services.md   # Paydunya, Uploadcare, Mailjet, RDS
│   ├── frontend.md
│   ├── design-system.md
│   ├── fixtures.md
│   └── deploy.md              # Heroku + Vercel + RDS
│
├── CLAUDE.md                  # Instructions Claude Code
├── docker-compose.yml         # Dev local uniquement
├── Makefile
└── setup.sh
```
