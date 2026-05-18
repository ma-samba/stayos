# ════════════════════════════════════════════════════════════════════════════
# STAYOS — Makefile
# Usage : make <commande>
# ════════════════════════════════════════════════════════════════════════════

.DEFAULT_GOAL := help
.PHONY: help install start stop restart build logs shell shell-db shell-redis \
        shell-front migrate migrate-run migrate-status migrate-tenant-all \
        fixtures db-reset validate-schema jwt cache \
        test test-unit test-functional test-security test-coverage test-setup \
        lint cs cs-fix stan worker worker-failed \
        npm-install npm-build tenant-provision logs-php logs-front ps down down-v

GREEN  = \033[0;32m
YELLOW = \033[0;33m
CYAN   = \033[0;36m
RED    = \033[0;31m
RESET  = \033[0m

help: ## Affiche cette aide
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) \
	| awk 'BEGIN {FS = ":.*?## "}; {printf "$(CYAN)%-26s$(RESET) %s\n", $$1, $$2}'

# ════════════════════════════════════════════════════════════════════════════
# INSTALLATION
# ════════════════════════════════════════════════════════════════════════════

install: ## 🚀 Installation complète du projet (1ère fois)
	@echo "$(GREEN)▶ Copie des fichiers .env.local...$(RESET)"
	@cp -n .env.local.template .env.local || echo "  .env.local existe déjà"
	@cp -n frontend/.env.local.template frontend/.env.local || echo "  frontend/.env.local existe déjà"
	@echo "$(GREEN)▶ Build des images Docker...$(RESET)"
	docker compose build --no-cache
	@echo "$(GREEN)▶ Démarrage des conteneurs...$(RESET)"
	docker compose up -d
	@echo "$(GREEN)▶ Attente PostgreSQL...$(RESET)"
	sleep 6
	@echo "$(GREEN)▶ Installation Composer...$(RESET)"
	docker compose exec php composer install
	@echo "$(GREEN)▶ Génération des clés JWT...$(RESET)"
	$(MAKE) jwt
	@echo "$(GREEN)▶ Base de données...$(RESET)"
	docker compose exec php php bin/console doctrine:database:create --if-not-exists
	docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction
	@echo "$(GREEN)▶ Fixtures...$(RESET)"
	docker compose exec php php bin/console doctrine:fixtures:load \
		--no-interaction \
		--purger=tenant_aware
	@echo "$(GREEN)▶ BDD de test...$(RESET)"
	$(MAKE) test-setup
	@echo "$(GREEN)▶ npm install...$(RESET)"
	docker compose exec frontend npm install
	@echo ""
	@echo "$(GREEN)══════════════════════════════════════════════════$(RESET)"
	@echo "$(GREEN)  ✅ Installation terminée !                       $(RESET)"
	@echo "$(GREEN)══════════════════════════════════════════════════$(RESET)"
	@echo "  API      : http://localhost:8080/api"
	@echo "  Frontend : http://localhost:5173"
	@echo "  Mail     : http://localhost:8025"
	@echo "  Mercure  : http://localhost:9090"
	@echo "$(GREEN)══════════════════════════════════════════════════$(RESET)"
	@echo "  savana.localhost  → admin@savana-hotel.sn / admin123"
	@echo "$(GREEN)══════════════════════════════════════════════════$(RESET)"

# ════════════════════════════════════════════════════════════════════════════
# DOCKER
# ════════════════════════════════════════════════════════════════════════════

start: ## ▶ Démarrer tous les conteneurs
	docker compose up -d
	@echo "$(GREEN)✅ Conteneurs démarrés$(RESET)"
	@echo "  API      → http://localhost:8080/api"
	@echo "  Frontend → http://localhost:5173"
	@echo "  Mail     → http://localhost:8025"
	@echo "  Mercure  → http://localhost:9090"

stop: ## ⏹ Arrêter tous les conteneurs
	docker compose stop

restart: ## 🔄 Redémarrer tous les conteneurs
	docker compose restart

build: ## 🔨 Rebuild les images Docker
	docker compose build

down: ## 🗑 Arrêter et supprimer les conteneurs (données conservées)
	docker compose down

down-v: ## ⚠️  Arrêter et supprimer conteneurs + volumes (PERTE DE DONNÉES)
	docker compose down -v

logs: ## 📋 Afficher les logs (tous les services)
	docker compose logs -f

logs-php: ## 📋 Logs PHP uniquement
	docker compose logs -f php

logs-front: ## 📋 Logs frontend
	docker compose logs -f frontend

logs-worker: ## 📋 Logs Messenger worker
	docker compose logs -f messenger

ps: ## 📊 Statut des conteneurs
	docker compose ps

# ════════════════════════════════════════════════════════════════════════════
# SHELLS
# ════════════════════════════════════════════════════════════════════════════

shell: ## 💻 Shell dans le conteneur PHP
	docker compose exec php sh

shell-db: ## 💻 Shell PostgreSQL
	docker compose exec db psql -U $${POSTGRES_USER} -d $${POSTGRES_DB}

shell-db-test: ## 💻 Shell PostgreSQL (BDD test)
	docker compose exec db psql -U $${POSTGRES_USER} -d stayos_test

shell-redis: ## 💻 Shell Redis CLI
	docker compose exec redis redis-cli

shell-front: ## 💻 Shell frontend
	docker compose exec frontend sh

# ════════════════════════════════════════════════════════════════════════════
# BASE DE DONNÉES
# ════════════════════════════════════════════════════════════════════════════

migrate: ## 🗄 Générer et exécuter les migrations
	docker compose exec php php bin/console doctrine:migrations:diff --no-interaction
	docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction

migrate-run: ## 🗄 Exécuter les migrations existantes
	docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction

migrate-status: ## 🗄 Statut des migrations
	docker compose exec php php bin/console doctrine:migrations:status

migrate-tenant-all: ## 🗄 Appliquer migrations sur tous les schemas tenant
	docker compose exec php php bin/console stayos:migrations:migrate-all-tenants

fixtures: ## 🌱 Recharger les fixtures
	docker compose exec php php bin/console doctrine:fixtures:load \
		--no-interaction \
		--purger=tenant_aware

db-reset: ## ⚠️  Reset complet BDD (drop + create + migrate + fixtures)
	docker compose exec php php bin/console doctrine:database:drop --force --if-exists
	docker compose exec php php bin/console doctrine:database:create
	docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction
	docker compose exec php php bin/console doctrine:fixtures:load \
		--no-interaction \
		--purger=tenant_aware

validate-schema: ## ✅ Valider le schéma Doctrine
	docker compose exec php php bin/console doctrine:schema:validate

# ════════════════════════════════════════════════════════════════════════════
# TENANT
# ════════════════════════════════════════════════════════════════════════════

tenant-provision: ## 🏨 Provisionner un tenant (make tenant-provision SLUG=mon-hotel)
	docker compose exec php php bin/console stayos:tenant:provision $(SLUG)

# ════════════════════════════════════════════════════════════════════════════
# JWT
# ════════════════════════════════════════════════════════════════════════════

jwt: ## 🔐 Générer les clés JWT
	docker compose exec php mkdir -p config/jwt
	docker compose exec php openssl genpkey \
		-out config/jwt/private.pem \
		-aes256 -algorithm rsa -pkeyopt rsa_keygen_bits:4096 \
		-pass pass:$${JWT_PASSPHRASE}
	docker compose exec php openssl pkey \
		-in config/jwt/private.pem \
		-out config/jwt/public.pem \
		-pubout -passin pass:$${JWT_PASSPHRASE}
	@echo "$(GREEN)✅ Clés JWT générées$(RESET)"

# ════════════════════════════════════════════════════════════════════════════
# CACHE
# ════════════════════════════════════════════════════════════════════════════

cache: ## 🗑 Vider le cache Symfony
	docker compose exec php php bin/console cache:clear
	docker compose exec php php bin/console cache:warmup

# ════════════════════════════════════════════════════════════════════════════
# TESTS
# ════════════════════════════════════════════════════════════════════════════

test-setup: ## 🔧 Créer et préparer la BDD de test
	docker compose exec php php bin/console doctrine:database:create --env=test --if-not-exists
	docker compose exec php php bin/console doctrine:migrations:migrate --env=test --no-interaction
	@echo "$(GREEN)✅ BDD de test prête$(RESET)"

test: ## 🧪 Lancer tous les tests
	docker compose exec php php bin/phpunit

test-unit: ## 🧪 Tests unitaires uniquement
	docker compose exec php php bin/phpunit tests/Unit

test-functional: ## 🧪 Tests fonctionnels uniquement
	docker compose exec php php bin/phpunit tests/Functional

test-security: ## 🔒 Tests sécurité et isolation multi-tenant (CRITIQUE)
	@echo "$(RED)▶ Tests isolation multi-tenant...$(RESET)"
	docker compose exec php php bin/phpunit tests/Functional/Security
	@echo "$(GREEN)✅ Tests sécurité OK$(RESET)"

test-coverage: ## 🧪 Tests avec couverture HTML (var/coverage/)
	docker compose exec php php bin/phpunit --coverage-html var/coverage
	@echo "$(GREEN)✅ Rapport : backend/var/coverage/index.html$(RESET)"

test-watch: ## 🧪 Tests en mode watch (relance à chaque modification)
	docker compose exec php php bin/phpunit --testdox

# ════════════════════════════════════════════════════════════════════════════
# CODE QUALITY
# ════════════════════════════════════════════════════════════════════════════

lint: ## 🔍 Lint Symfony (YAML, container, routes)
	docker compose exec php php bin/console lint:yaml config
	docker compose exec php php bin/console lint:container
	docker compose exec php php bin/console debug:router

cs: ## 🔍 PHP-CS-Fixer (vérification)
	docker compose exec php vendor/bin/php-cs-fixer fix --dry-run --diff

cs-fix: ## 🔧 PHP-CS-Fixer (correction automatique)
	docker compose exec php vendor/bin/php-cs-fixer fix

stan: ## 🔍 PHPStan (analyse statique niveau 5)
	docker compose exec php vendor/bin/phpstan analyse src --level=5

# ════════════════════════════════════════════════════════════════════════════
# MESSENGER
# ════════════════════════════════════════════════════════════════════════════

worker: ## 📨 Lancer le worker Messenger manuellement
	docker compose exec php php bin/console messenger:consume async -vv

worker-failed: ## 📨 Voir les messages échoués
	docker compose exec php php bin/console messenger:failed:show

worker-retry: ## 📨 Rejouer les messages échoués
	docker compose exec php php bin/console messenger:failed:retry

# ════════════════════════════════════════════════════════════════════════════
# FRONTEND
# ════════════════════════════════════════════════════════════════════════════

npm-install: ## 📦 Installer les dépendances npm
	docker compose exec frontend npm install

npm-build: ## 🏗 Build production du frontend
	docker compose exec frontend npm run build

npm-lint: ## 🔍 Lint Vue.js
	docker compose exec frontend npm run lint
