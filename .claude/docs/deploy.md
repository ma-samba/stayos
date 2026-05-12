# Déploiement — Référence

## Architecture

```
Dev local → Docker Compose
Backend   → Heroku (buildpack PHP)
Frontend  → Vercel (Vue.js)
BDD       → Amazon RDS PostgreSQL 16
Cache     → Heroku Redis addon
Emails    → Mailjet
Images    → Uploadcare CDN
```

## Environnements

| Env | Backend | Frontend | Branche git |
|---|---|---|---|
| Dev local | localhost:8080 | localhost:5173 | feature/* |
| Staging | stayos-staging.herokuapp.com | stayos-staging.vercel.app | develop |
| Production | stayos-api.herokuapp.com | stayos.sn | main |

---

## Backend — Heroku

### Prérequis
```bash
# Installer Heroku CLI
brew install heroku/brew/heroku  # macOS
# ou https://devcenter.heroku.com/articles/heroku-cli

heroku login
```

### Création de l'app (1ère fois)
```bash
cd backend/

# Créer l'app Heroku
heroku create stayos-api

# Ajouter les buildpacks
heroku buildpacks:add heroku/php

# Ajouter Redis
heroku addons:create heroku-redis:mini

# Configurer les variables d'environnement
heroku config:set APP_ENV=prod
heroku config:set APP_DEBUG=0
heroku config:set APP_SECRET=$(openssl rand -hex 32)
heroku config:set DATABASE_URL="postgresql://user:pass@rds-host.amazonaws.com:5432/stayos_prod?serverVersion=16"
heroku config:set JWT_PASSPHRASE="your_strong_passphrase"
heroku config:set MAILER_DSN="mailjet+api://API_KEY:API_SECRET@default"
heroku config:set FRONTEND_URL="https://stayos.vercel.app"
heroku config:set PAYDUNYA_MASTER_KEY="..."
heroku config:set PAYDUNYA_PRIVATE_KEY="..."
heroku config:set PAYDUNYA_TOKEN="..."
heroku config:set PAYDUNYA_MODE="live"
heroku config:set UPLOADCARE_PUBLIC_KEY="..."
heroku config:set UPLOADCARE_SECRET_KEY="..."
heroku config:set MERCURE_JWT_SECRET="..."
heroku config:set APP_DOMAIN="stayos.sn"
```

### Clés JWT sur Heroku
```bash
# Générer les clés localement
openssl genpkey -out private.pem -aes256 -algorithm rsa -pkeyopt rsa_keygen_bits:4096 -pass pass:YOUR_PASSPHRASE
openssl pkey -in private.pem -out public.pem -pubout -passin pass:YOUR_PASSPHRASE

# Les encoder en base64 et les stocker comme config vars
heroku config:set JWT_PRIVATE_KEY="$(base64 -w 0 private.pem)"
heroku config:set JWT_PUBLIC_KEY="$(base64 -w 0 public.pem)"

# Dans lexik_jwt_authentication.yaml, lire depuis les env vars base64
# secret_key: '%env(base64:JWT_PRIVATE_KEY)%'
# public_key: '%env(base64:JWT_PUBLIC_KEY)%'
```

### Déploiement
```bash
cd backend/

# Premier déploiement
git subtree push --prefix backend heroku main

# Ou configurer le remote git Heroku et pusher
git remote add heroku https://git.heroku.com/stayos-api.git
git push heroku main

# Heroku exécute automatiquement la release phase du Procfile :
# → doctrine:migrations:migrate
# → cache:clear
```

### Commandes utiles Heroku
```bash
heroku logs --tail                    # Logs en temps réel
heroku run php bin/console ...        # Commandes Symfony
heroku run php bin/console doctrine:migrations:status
heroku run php bin/console stayos:tenant:provision {slug}
heroku ps                             # Statut des dynos
heroku restart                        # Redémarrer
```

### Procfile (backend/Procfile)
```
web: vendor/bin/heroku-php-nginx -C docker/nginx/heroku.conf public/
worker: php bin/console messenger:consume async --time-limit=3600 -vv
release: php bin/console doctrine:migrations:migrate --no-interaction && php bin/console cache:clear
```

---

## Frontend — Vercel

### Prérequis
```bash
npm install -g vercel
vercel login
```

### Création du projet (1ère fois)
```bash
cd frontend/

# Lier au projet Vercel (interface interactive)
vercel

# Ou directement
vercel --prod
```

### Configuration Vercel (vercel.json)
```json
{
  "buildCommand": "npm run build",
  "outputDirectory": "dist",
  "framework": "vue",
  "rewrites": [{ "source": "/(.*)", "destination": "/index.html" }]
}
```

### Variables d'environnement Vercel
Configurer dans le dashboard Vercel → Settings → Environment Variables :

```
VITE_API_URL              = https://stayos-api.herokuapp.com/api
VITE_MERCURE_URL          = https://stayos-mercure.herokuapp.com/.well-known/mercure
VITE_UPLOADCARE_PUBLIC_KEY = your_public_key
VITE_APP_DOMAIN           = stayos.sn
```

### Déploiement automatique
Connecter le repo GitHub dans le dashboard Vercel :
- **Production** : branch `main` → déploiement auto sur push
- **Preview** : toutes les autres branches → URL de preview unique par PR

### Domaine personnalisé
Dans Vercel → Settings → Domains → ajouter `stayos.sn` ou `app.stayos.sn`
Vercel génère le certificat SSL automatiquement.

---

## Amazon RDS — Setup

### Création de l'instance (AWS Console)
```
Engine          : PostgreSQL 16.x
Template        : Free tier (dev) / Production (prod)
Instance class  : db.t3.micro (dev) → db.t3.small (prod)
Storage         : 20 GB gp3
Username        : stayos_user
Password        : [générer un mot de passe fort]
VPC             : Default VPC
Public access   : Yes (avec security group restreint)
```

### Security Group RDS
Autoriser uniquement :
- Port 5432 depuis les IPs des dynos Heroku
- Port 5432 depuis votre IP locale (pour les migrations manuelles)

### Récupérer l'URL de connexion
```
Format : postgresql://username:password@endpoint.region.rds.amazonaws.com:5432/dbname?serverVersion=16
```

### Première migration
```bash
# Depuis Heroku (après avoir configuré DATABASE_URL)
heroku run php bin/console doctrine:database:create --if-not-exists
heroku run php bin/console doctrine:migrations:migrate --no-interaction
```

---

## Subdomains multi-tenant en production

```
DNS : *.stayos.sn → CNAME → stayos-api.herokuapp.com

Heroku : ajouter le wildcard domain
heroku domains:add '*.stayos.sn'

Nginx (heroku.conf) : lire le subdomain depuis $HTTP_HOST
→ transmettre comme header X-Tenant-Slug à Symfony
→ TenantMiddleware résout le tenant
```

---

## Monitoring

- **Uptime** : UptimeRobot sur `GET /api/health` — alerte SMS/email si down
- **Logs Heroku** : `heroku logs --tail` + addon Papertrail (optionnel)
- **Errors** : Sentry (bundle sentry/sentry-symfony)
- **Vercel** : analytics intégrés dans le dashboard

---

## Sauvegardes BDD

RDS gère les backups automatiques (7 jours).
Snapshot manuel avant chaque migration importante :

```bash
aws rds create-db-snapshot \
  --db-instance-identifier stayos-prod \
  --db-snapshot-identifier stayos-pre-v$(date +%Y%m%d)
```
