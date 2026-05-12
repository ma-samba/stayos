#!/usr/bin/env bash
# ════════════════════════════════════════════════════════════════════════════
# STAYOS — Script d'initialisation locale
# Usage : bash setup.sh
# ════════════════════════════════════════════════════════════════════════════
set -e

GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
CYAN='\033[0;36m'
RESET='\033[0m'

info()    { echo -e "${CYAN}[INFO]${RESET} $1"; }
success() { echo -e "${GREEN}[OK]${RESET} $1"; }
warn()    { echo -e "${YELLOW}[WARN]${RESET} $1"; }
error()   { echo -e "${RED}[ERREUR]${RESET} $1"; exit 1; }

echo ""
echo -e "${GREEN}╔══════════════════════════════════════════════════╗${RESET}"
echo -e "${GREEN}║        StayOS PMS — Installation locale          ║${RESET}"
echo -e "${GREEN}║   Symfony 7 · Vue.js 3 · Heroku · Vercel · RDS  ║${RESET}"
echo -e "${GREEN}╚══════════════════════════════════════════════════╝${RESET}"
echo ""

# ── Vérification des prérequis ───────────────────────────────────────────────
info "Vérification des prérequis..."

command -v docker >/dev/null 2>&1       || error "Docker n'est pas installé. Voir https://docs.docker.com/get-docker/"
command -v docker compose >/dev/null 2>&1 || error "Docker Compose n'est pas installé."
command -v make >/dev/null 2>&1         || warn "Make n'est pas installé. Utilisez docker compose directement."
command -v node >/dev/null 2>&1         || warn "Node.js non trouvé — le frontend sera géré via Docker."

DOCKER_VERSION=$(docker --version | grep -oE '[0-9]+\.[0-9]+' | head -1)
info "Docker version : $DOCKER_VERSION"
success "Prérequis OK"

# ── Copie du .env.local ──────────────────────────────────────────────────────
info "Configuration de l'environnement backend..."
if [ ! -f ".env.local" ]; then
    cp .env.local.template .env.local
    success ".env.local créé depuis le template"
    echo ""
    warn "⚠️  Pensez à renseigner vos clés dans .env.local :"
    warn "   - PAYDUNYA_MASTER_KEY / PRIVATE_KEY / TOKEN"
    warn "   - UPLOADCARE_PUBLIC_KEY / SECRET_KEY"
    warn "   - VITE_UPLOADCARE_PUBLIC_KEY (pour le frontend)"
    echo ""
    read -p "Appuyez sur Entrée pour continuer avec les valeurs par défaut..."
else
    warn ".env.local existe déjà — conservation des valeurs actuelles"
fi

# Copie du .env.local frontend
info "Configuration de l'environnement frontend..."
if [ ! -f "frontend/.env.local" ]; then
    cp frontend/.env.local.template frontend/.env.local
    success "frontend/.env.local créé"
else
    warn "frontend/.env.local existe déjà"
fi

export $(grep -v '^#' .env.local | xargs) 2>/dev/null || true

# ── /etc/hosts pour les subdomains locaux ────────────────────────────────────
info "Vérification de /etc/hosts pour les subdomains locaux..."
if ! grep -q "savana.localhost" /etc/hosts 2>/dev/null; then
    warn "Ajoutez ces lignes à /etc/hosts pour tester les subdomains :"
    echo ""
    echo "  127.0.0.1  savana.localhost"
    echo "  127.0.0.1  villa-collines.localhost"
    echo "  127.0.0.1  superadmin.localhost"
    echo ""
    warn "Commande sudo : echo '127.0.0.1 savana.localhost' | sudo tee -a /etc/hosts"
    echo ""
    read -p "Continuer sans configurer /etc/hosts ? [Enter]"
else
    success "/etc/hosts déjà configuré"
fi

# ── Build et démarrage Docker ────────────────────────────────────────────────
info "Build des images Docker (peut prendre quelques minutes)..."
docker compose build --no-cache
success "Images construites"

info "Démarrage des conteneurs..."
docker compose up -d
success "Conteneurs démarrés"

# ── Attente PostgreSQL ───────────────────────────────────────────────────────
info "Attente de PostgreSQL..."
MAX_TRIES=30
COUNT=0
until docker compose exec -T db pg_isready -U "${POSTGRES_USER:-stayos_user}" -d "${POSTGRES_DB:-stayos_db}" >/dev/null 2>&1; do
    COUNT=$((COUNT + 1))
    if [ $COUNT -ge $MAX_TRIES ]; then
        error "PostgreSQL ne répond pas après $MAX_TRIES tentatives"
    fi
    echo -n "."
    sleep 2
done
echo ""
success "PostgreSQL prêt"

# ── Dépendances Composer ─────────────────────────────────────────────────────
info "Installation des dépendances PHP (Composer)..."
docker compose exec -T php composer install --no-interaction --prefer-dist
success "Dépendances PHP installées"

# ── Clés JWT ─────────────────────────────────────────────────────────────────
info "Génération des clés JWT..."
docker compose exec -T php mkdir -p config/jwt

JWT_PASSPHRASE="${JWT_PASSPHRASE:-stayos_jwt_dev_secret}"

docker compose exec -T php openssl genpkey \
    -out config/jwt/private.pem \
    -aes256 -algorithm rsa -pkeyopt rsa_keygen_bits:4096 \
    -pass pass:"${JWT_PASSPHRASE}" 2>/dev/null

docker compose exec -T php openssl pkey \
    -in config/jwt/private.pem \
    -out config/jwt/public.pem \
    -pubout -passin pass:"${JWT_PASSPHRASE}" 2>/dev/null

success "Clés JWT générées"

# ── Base de données ──────────────────────────────────────────────────────────
info "Création de la base de données..."
docker compose exec -T php php bin/console doctrine:database:create --if-not-exists --no-interaction
success "Base de données créée"

info "Exécution des migrations (schema public)..."
docker compose exec -T php php bin/console doctrine:migrations:migrate --no-interaction
success "Migrations exécutées"

info "Chargement des fixtures..."
info "→ Provisioning des schemas PostgreSQL pour les hôtels de démo..."
docker compose exec -T php php bin/console doctrine:fixtures:load --no-interaction
success "Fixtures chargées"

# ── Frontend ─────────────────────────────────────────────────────────────────
info "Installation des dépendances npm..."
docker compose exec -T frontend npm install
success "Dépendances npm installées"

# ── Récapitulatif ────────────────────────────────────────────────────────────
echo ""
echo -e "${GREEN}╔══════════════════════════════════════════════════════════╗${RESET}"
echo -e "${GREEN}║          ✅ Installation terminée avec succès !          ║${RESET}"
echo -e "${GREEN}╚══════════════════════════════════════════════════════════╝${RESET}"
echo ""
echo -e "  ${CYAN}Services locaux${RESET}"
echo -e "  API Symfony    → http://localhost:8080/api"
echo -e "  Frontend Vue   → http://localhost:5173"
echo -e "  Mailpit (mail) → http://localhost:8025  ← tous les emails atterrissent ici"
echo -e "  Mercure (SSE)  → http://localhost:9090"
echo -e "  PostgreSQL     → localhost:5432"
echo -e "  Redis          → localhost:6379"
echo ""
echo -e "  ${CYAN}Hôtel démo 1 — Hôtel Savana Dakar (Plan Pro)${RESET}"
echo -e "  URL        : http://savana.localhost:8080"
echo -e "  Admin      : admin@savana-hotel.sn / admin123"
echo -e "  Réception  : reception@savana-hotel.sn / recep123"
echo ""
echo -e "  ${CYAN}Hôtel démo 2 — Villa Collines Saly (Plan Starter)${RESET}"
echo -e "  URL        : http://villa-collines.localhost:8080"
echo -e "  Admin      : admin@villa-collines.sn / admin123"
echo ""
echo -e "  ${CYAN}Super Admin plateforme${RESET}"
echo -e "  URL   : http://superadmin.localhost:8080"
echo -e "  Email : superadmin@stayos.sn / superadmin123"
echo ""
echo -e "  ${YELLOW}⚠️  Services externes à configurer dans .env.local :${RESET}"
echo -e "  Paydunya    → PAYDUNYA_MASTER_KEY / PRIVATE_KEY / TOKEN (mode test OK pour démarrer)"
echo -e "  Uploadcare  → UPLOADCARE_PUBLIC_KEY / SECRET_KEY"
echo -e "  Mailjet     → uniquement en prod (Mailpit suffit en dev)"
echo ""
echo -e "  ${YELLOW}Commandes utiles :${RESET}"
echo -e "  make logs              → Voir les logs"
echo -e "  make shell             → Shell PHP"
echo -e "  make shell-db          → Console PostgreSQL"
echo -e "  make migrate           → Créer/exécuter migrations"
echo -e "  make fixtures          → Recharger les données de démo"
echo -e "  make tenant-provision  → Provisionner un nouveau tenant"
echo ""
echo -e "  ${YELLOW}Déploiement :${RESET}"
echo -e "  Backend  → git push heroku main"
echo -e "  Frontend → vercel --prod (depuis le dossier frontend/)"
echo -e "  Voir .claude/docs/deploy.md pour le guide complet"
echo ""
