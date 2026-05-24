# StayOS — Plan de développement

## Workflow
Claude Code génère le code → l'utilisateur valide → Claude (chat) relit et vérifie la cohérence.
Pour chaque sprint : demander le prompt Claude Code dans le chat, puis soumettre le code généré pour relecture.

## Statut global
- Sprint courant : **Sprint 9 — Tarifs & promotions**
- Dernière mise à jour : 24 mai 2026
- Sprints terminés : 1, 2, 3, 4, 5, 6, 7, 8

---

## Vue d'ensemble — 14 sprints (~14 semaines)

```
Phase 1 — Fondations      (S1–S3)  : Infrastructure, Auth, BDD
Phase 2 — Core métier     (S4–S7)  : Chambres, Réservations, Clients, Facturation
Phase 3 — Opérations      (S8–S9)  : Housekeeping, Tarifs
Phase 4 — Intelligence    (S10–S11): Dashboard, Temps réel
Phase 5 — SaaS            (S12–S13): Abonnements, SuperAdmin
Phase 6 — Production      (S14)    : Sécurité finale, déploiement
```

---

## Sprints détaillés

### ✅ Sprint 1 — Infrastructure & multi-tenant
**Objectif** : Docker tourne, le multi-tenant fonctionne, on peut router une requête vers le bon schema PostgreSQL.

**Backend**
- [ ] `TenantContext` — holder du tenant courant (request-scoped)
- [ ] `TenantMiddleware` — lit le subdomain, charge le tenant, pose le `search_path`
- [ ] `TenantProvisioner` — crée le schema `hotel_{uuid}` + applique les migrations tenant
- [ ] `TenantResolver` — résout le tenant depuis la requête HTTP
- [ ] Migration globale : table `tenants` dans le schema `public`
- [ ] Commande Symfony `stayos:tenant:provision {slug}`
- [ ] Endpoint `GET /api/health` complet (db, redis, mercure)

**Tests**
- [ ] `TenantMiddlewareTest` — subdomain connu → bon schema
- [ ] `TenantMiddlewareTest` — subdomain inconnu → 404
- [ ] `MultiTenantIsolationTest` — token hôtel A ne fonctionne pas sur hôtel B

**Livrable** : `curl -H "Host: demo.localhost" http://localhost:8080/api/health` → `{"status":"ok"}`

---

### ✅ Sprint 2 — Auth & onboarding
**Objectif** : un hôtel peut s'inscrire, recevoir un OTP, se connecter, obtenir un JWT.

**Backend**
- [ ] Entités Platform : `Plan`, `Subscription`, `User`, `StaffUser`, `OtpToken`
- [ ] `OtpService` — génère, envoie via Mailjet, vérifie
- [ ] `OnboardingService` — inscription → OTP → provisioning → abonnement essai
- [ ] `AuthController` — login, refresh, logout, send-otp, verify-otp
- [ ] `OnboardingController` — register, steps
- [ ] `JWTCreatedListener` — enrichit le JWT (tenant + role + plan + features)
- [ ] `TenantUserProvider` — charge StaffUser depuis le bon schema

**Frontend**
- [ ] `LoginView.vue`
- [ ] `RegisterView.vue` (tunnel 4 étapes)
- [ ] `OtpView.vue`
- [ ] `auth.store.ts` avec refresh token silencieux
- [ ] `tenant.store.ts` avec feature flags

**Tests**
- [ ] `LoginTest` — credentials corrects → JWT
- [ ] `LoginTest` — mauvais password → 401
- [ ] `LoginTest` — rate limit → 429 après 5 tentatives
- [ ] `OtpTest` — OTP expiré → erreur
- [ ] `OtpTest` — 3 mauvais codes → verrouillage
- [ ] `OnboardingTest` — inscription complète → tenant provisionné

**Livrable** : inscription, vérification email OTP, connexion JWT fonctionnels

---

### ✅ Sprint 3 — Entités & migrations
**Objectif** : toutes les entités Doctrine existent, schema BDD complet, fixtures chargent.

**Backend**
- [ ] Entités Hotel : `HotelProfile`, `Floor`, `RoomType`, `Room`, `Guest`, `GuestDocument`
- [ ] Entités Hotel : `Reservation`, `Invoice`, `InvoiceLine`, `Payment`
- [ ] Entités Hotel : `CleaningTask`, `RatePlan`, `SeasonalRate`, `Promotion`, `ChannelMapping`
- [ ] Entité : `AuditLog`
- [ ] Tous les enums PHP 8.1 (RoomStatus, ReservationStatus, CleaningStatus...)
- [ ] Migrations global/ + tenant/
- [ ] DataFixtures complètes (2 hôtels, chambres, clients, réservations, paiements)
- [ ] Repositories de base (search_path automatique)
- [ ] `AuditService` + listener Doctrine

**Tests**
- [ ] `ReservationTest` — `getNights()`, `getTotalXof()`, `getBalanceXof()`
- [ ] `InvoiceTest` — `getTaxXof()`, `getTotalXof()`
- [ ] `make fixtures` charge sans erreur
- [ ] `make validate-schema` passe

**Livrable** : `make fixtures` charge 2 hôtels complets avec données réalistes

---

### ✅ Sprint 4 — Chambres & disponibilité
**Objectif** : plan des chambres, changement de statut, vérification disponibilités.

**Backend**
- [ ] `RoomController` — CRUD + `PATCH /rooms/{id}/status`
- [ ] `GET /rooms/available?from=&to=&adults=`
- [ ] `ReservationEngine::isAvailable()` + `getAvailableRooms()`
- [ ] `RoomStatusChangedEvent` + listener Mercure

**Frontend**
- [ ] `RoomsView.vue` — plan d'étage avec `RoomCard.vue`
- [ ] `RoomDetailView.vue` — fiche chambre
- [ ] Filtres disponibilité (date picker + adultes)
- [ ] Mise à jour temps réel via Mercure

**Tests**
- [ ] `RoomAvailabilityTest` — chambre occupée non retournée
- [ ] `RoomAvailabilityTest` — chambre en maintenance non retournée
- [ ] `RoomStatusTest` — changement statut → audit log créé
- [ ] `MultiTenantTest` — chambres d'un autre hôtel invisibles

**Livrable** : plan d'étage fonctionnel, statuts en temps réel

---

### ✅ Sprint 5 — Réservations
**Objectif** : créer, modifier, annuler une réservation. Planning Gantt.

**Backend**
- [ ] `ReservationController` — CRUD complet
- [ ] `ReservationEngine::create()` — disponibilité + prix + numéro confirmation
- [ ] `ReservationEngine::cancel()` — libère la chambre
- [ ] `ConflictChecker` — détecte les chevauchements de dates
- [ ] `PriceCalculator` — tarif de base + règles saisonnières
- [ ] Audit log sur toutes les actions

**Frontend**
- [ ] `ReservationsView.vue` — liste + filtres
- [ ] `GanttCalendar.vue` — planning visuel
- [ ] `ReservationForm.vue` — création avec vérification disponibilité live
- [ ] `ReservationDetailView.vue` — fiche complète

**Tests**
- [ ] `ReservationConflictTest` — double booking impossible
- [ ] `ReservationConflictTest` — dates adjacentes OK
- [ ] `PriceCalculatorTest` — calcul base + TVA 18%
- [ ] `PriceCalculatorTest` — tarif saisonnier appliqué
- [ ] `ReservationCrudTest` — CRUD complet + audit log

**Livrable** : réservation bout en bout, détection conflits, planning Gantt

---

### ✅ Sprint 6 — Clients & check-in/out
**Objectif** : gérer le profil client, enregistrer les arrivées/départs.

**Backend**
- [x] `GuestController` — CRUD + recherche fulltext
- [x] `GuestService::search()` — nom/email/document
- [x] `GuestService::findOrCreate()` — évite les doublons
- [x] `ReservationEngine::checkIn()` — chambre OCCUPIED + tâche ménage + audit
- [x] `ReservationEngine::checkOut()` — chambre CLEANING + facture draft + audit
- [x] Upload document → Uploadcare + stockage URL

**Frontend**
- [x] `GuestsView.vue` — liste + recherche rapide
- [x] `GuestProfileView.vue` — fiche + historique séjours + édition
- [x] `GuestSearch.vue` — recherche rapide réception
- [x] `CheckInModal.vue` — confirmation + upload document
- [x] `CheckOutModal.vue` — confirmation + solde restant + montant total

**Tests**
- [x] `GuestControllerTest` — auth gating + cross-tenant isolation
- [x] `ReservationEngineTest` — checkOut génère facture draft
- [x] `ReservationEngineTest` — checkOut non-bloquant si facture échoue
- [x] `InvoiceDraftServiceTest` — TVA TTC, idempotence, ligne facture
- [x] `ReservationEngineTest` — checkIn → chambre OCCUPIED

**Ajouts non planifiés (Sprint 6)**
- [x] `TenantMigrator` versionné — interface, registry, migrator, CLI `stayos:tenant:migrate`
- [x] Sécurité TenantMigrator — validation schema name (anti-injection SQL), dry-run read-only
- [x] `InvoiceDraftService` — génération automatique facture brouillon au check-out
- [x] Migration `AddGuestDocumentUrl` — champ `document_url` sur `guests`
- [x] `formatCurrency()` helper centralisé (frontend)
- [x] `GuestProfileView` — mode lecture + mode édition avec formulaire complet
- [x] Makefile — chargement `.env`, cibles psql robustes, commandes tenant

**Livrable** : workflow réception complet (arrivée → séjour → départ)

---

### ✅ Sprint 7 — Facturation & paiements
**Objectif** : émettre une facture, paiement Paydunya, PDF envoyé par email.

**Backend**
- [x] `InvoiceService::generateFromReservation()` — lignes auto + TVA 18%
- [x] `InvoiceService::generatePdf()` — Dompdf (remplace KnpSnappy, plus simple à déployer)
- [x] `InvoiceService::recordPayment()` — enregistre + calcule solde (bcmath)
- [x] `PaydunyaService` — createCheckout, verifyPayment via gateway pattern
- [x] `PaydunyaWebhookController` — IPN multi-tenant + Mercure
- [x] `EmailService::sendInvoice()` — PDF en PJ via Mailjet
- [x] Template email facture (Twig)

**Frontend**
- [x] `BillingView.vue` — liste factures
- [x] `InvoiceDetailView.vue` — détail + paiements reçus
- [x] `InvoicePreview.vue` — aperçu avant envoi
- [x] `PaymentForm.vue` — Wave, OM, espèces, carte
- [x] `CashRegister.vue` — caisse du jour

**Tests**
- [x] `InvoiceTest` — TVA 18% correcte + soldes bcmath (15 tests, 34 assertions)
- [x] `InvoiceServiceTest` — issue(), recordPayment(), transitions statut (6 tests, 15 assertions)
- [x] `PaydunyaWebhookHandlerTest` — IPN complet (11 tests, 59 assertions)
- [x] `InvoiceControllerTest` — auth gating + IPN public (10 tests fonctionnels)

**Ajouts notables (Sprint 7)**
- [x] Architecture gateway : `PaymentGatewayInterface` + `PaymentGatewayRegistry` + `PaymentConfirmation` — abstraction multi-passerelle
- [x] IPN multi-tenant : résolution tenant via query param `?tenant=slug`, switch search_path, restore après traitement
- [x] Idempotence IPN : verrouillage pessimiste (`LockMode::PESSIMISTIC_WRITE`) dans `wrapInTransaction` — prévient les doublons sur retries Paydunya
- [x] Anti-fraude : vérification montant serveur via `gateway->confirmPayment()` (source de vérité), rejet si montant diverge
- [x] Sérialisation `getCompletedPayments()` — filtre PAID uniquement via `#[SerializedName('payments')]`, les PENDING/FAILED ne fuient jamais vers le frontend
- [x] Soldes bcmath (`getPaidXof`, `getBalanceXof`, `isFullyPaid`) — jamais de float pour les montants XOF
- [x] `resolvePaymentMethod()` — résolution Wave/OrangeMoney/Card depuis le payload brut Paydunya
- [x] Dompdf au lieu de KnpSnappy — plus simple, pas de dépendance wkhtmltopdf
- [x] Formatage dates défensif (frontend) — `null`, `undefined`, `Invalid Date` → `—`

**Livrable** : facturation complète, paiement Paydunya, PDF par email

---

### ✅ Sprint 8 — Housekeeping
**Objectif** : le personnel de ménage voit ses tâches, les met à jour depuis mobile.

**Backend**
- [x] `CleaningTask` entité + `CleaningStatus`/`CleaningType` enums
- [x] `CleaningTaskRepository` — findForBoard(), hasActiveTaskForRoomOnDate()
- [x] `HousekeepingService::generateDailyTasks()` — recouches STAY_OVER (exclut jour arrivée + départ)
- [x] `HousekeepingService::updateStatus()` — machine à états (INSPECTED terminal, DONE libère chambre)
- [x] Tâche DEPARTURE créée au check-out (ReservationEngine) avec garde anti-doublon
- [x] `CleaningTaskController` — GET tasks + PATCH status, RBAC ROLE_ACCESS_HOUSEKEEPING
- [x] Notification Mercure task.assigned + task.updated
- [x] HOUSEKEEPER ne voit que ses propres tâches (filtrage controller)

**Frontend**
- [x] `HousekeepingView.vue` — kanban 5 colonnes (PENDING → IN_PROGRESS → DONE → INSPECTED + SKIPPED)
- [x] `TaskCard.vue` — numéro chambre, type, assigné, heure, actions contextuelles
- [x] Confirmation inline "Ignorer" + bouton "Réactiver"
- [x] Vue mobile responsive (1/2/3/5 colonnes selon breakpoint)
- [x] Notification toast Mercure quand tâche assignée (ciblée par userId)
- [x] Page de login manquante créée (LoginView + auth.service)

**Sécurité**
- [x] RBAC complet par rôle (backend security.yaml role_hierarchy + IsGranted sur tous les controllers)
- [x] Frontend : canAccess(), firstAccessiblePath(), sidebar filtrée, route guards avec meta.module
- [x] UUID utilisateur dans le JWT (claim `uid`) pour ciblage Mercure

**Tests**
- [x] `ReservationEngineTest` — 11 tests (dont checkout crée DEPARTURE, anti-doublon)
- [x] `HousekeepingServiceTest` — 6 tests (machine à états, libération chambre, timestamps)
- [x] `HousekeepingGenerateTest` — 5 tests (exclusion arrivée/départ, idempotence, 1 nuit)
- [x] `RoomServiceTest` — 7 tests (fixé : constructor mismatch)

**Livrable** : kanban housekeeping fonctionnel, RBAC complet, 76 tests unitaires verts

---

### ⬜ Sprint 9 — Tarifs & promotions
**Objectif** : plans tarifaires, tarifs saisonniers, codes promo.

**Backend**
- [ ] `RatePlanController` — CRUD plans tarifaires
- [ ] `SeasonalRateController` — tarifs par période
- [ ] `PromotionController` — codes promo avec règles
- [ ] `PriceCalculator` — saisonnalité + promos intégrées
- [ ] Feature flag `revenue_management` (Plan Pro)

**Frontend**
- [ ] `RatesView.vue` — plans + calendrier tarifaire
- [ ] `SeasonalRateForm.vue` — période + tarif
- [ ] `PromotionForm.vue` — code promo

**Tests**
- [ ] `PriceCalculatorTest` — haute saison appliquée
- [ ] `PriceCalculatorTest` — promo valide réduit le total
- [ ] `PriceCalculatorTest` — promo expirée ignorée
- [ ] `RateAccessTest` — STARTER ne peut pas accéder aux tarifs avancés

**Livrable** : revenue management basique opérationnel

---

### ⬜ Sprint 10 — Dashboard & rapports
**Objectif** : tableau de bord temps réel, rapports d'occupation et revenus.

**Backend**
- [ ] `DashboardService` — KPIs du jour, taux d'occupation, RevPAR
- [ ] `ReportController` — occupation, revenus, RevPAR sur période
- [ ] Export CSV + Excel (PhpSpreadsheet)
- [ ] Feature flag `advanced_reports` (Plan Pro)

**Frontend**
- [ ] `DashboardView.vue` — stat cards + graphiques ApexCharts
- [ ] `OccupancyChart.vue` — taux d'occupation hebdo/mensuel
- [ ] `RevenueChart.vue` — CA sur période
- [ ] `RevPARChart.vue` — Revenue Per Available Room
- [ ] `ReportsView.vue` — générateur + export

**Tests**
- [ ] `DashboardTest` — KPIs calculés correctement
- [ ] `RevPARTest` — formule correcte
- [ ] `ReportExportTest` — CSV généré avec bonnes colonnes

**Livrable** : dashboard opérationnel avec vrais KPIs hôteliers

---

### ⬜ Sprint 11 — Notifications temps réel
**Objectif** : tous les événements Mercure fonctionnent, centre de notifications frontend.

**Backend**
- [ ] `MercurePublisher` — service centralisé
- [ ] Topics : `room.status.changed`, `reservation.created`, `task.assigned`, `payment.received`
- [ ] Alertes automatiques : arrivées du jour, départs oubliés, tâches non assignées

**Frontend**
- [ ] `NotificationCenter.vue` — cloche avec badge non lues
- [ ] `NotificationItem.vue` — format par type
- [ ] Toasts automatiques sur événements temps réel
- [ ] Plan d'étage mis à jour live sans rechargement

**Tests**
- [ ] `MercureTest` — événement publié au bon topic
- [ ] `MercureTest` — topic d'un autre tenant inaccessible

**Livrable** : réception notifiée en temps réel de toute activité

---

### ⬜ Sprint 12 — Abonnements & plans
**Objectif** : choisir un plan, payer l'abonnement, upgrader/downgrader.

**Backend**
- [ ] `AbonnementService` — create trial, activate, suspend, checkExpirations
- [ ] `SubscriptionController` — voir plan, upgrader, annuler
- [ ] `SaasInvoiceService` — facturation mensuelle via Paydunya
- [ ] Scheduler Messenger : vérification expirations quotidienne
- [ ] Emails : essai expire J-7, J-1, suspendu

**Frontend**
- [ ] `SubscriptionView.vue` — plan actuel + limites utilisées
- [ ] `PricingView.vue` — cards plans + CTA upgrade
- [ ] `UpgradeModal.vue` — confirmation + paiement Paydunya
- [ ] `BillingHistoryView.vue` — historique factures SaaS

**Tests**
- [ ] `SubscriptionTest` — essai expire → tenant suspendu
- [ ] `SubscriptionTest` — upgrade → nouvelles features accessibles
- [ ] `FeatureGuardTest` — feature Pro inaccessible en Starter

**Livrable** : monétisation fonctionnelle, abonnements automatisés

---

### ⬜ Sprint 13 — SuperAdmin & métriques plateforme
**Objectif** : interface opérateur pour gérer tous les tenants et monitorer la plateforme.

**Backend**
- [ ] `SuperAdminController` — liste tenants, détail, suspendre, activer
- [ ] `PlatformMetricsService` — MRR, churn, nouveaux tenants, erreurs
- [ ] Firewall séparé + IP whitelist prod

**Frontend**
- [ ] `TenantsListView.vue` — statuts, plans, dernière activité
- [ ] `TenantDetailView.vue` — détail hôtel + actions admin
- [ ] `PlatformMetricsView.vue` — MRR, churn, queue size, erreurs

**Tests**
- [ ] `SuperAdminTest` — token hôtel bloqué sur `/superadmin`
- [ ] `SuperAdminTest` — suspension → tenant renvoie 402

**Livrable** : back-office opérateur complet

---

### ⬜ Sprint 14 — Production-ready
**Objectif** : app blindée, performante, déployée Heroku + Vercel + RDS.

**Checklist**
- [ ] Audit sécurité : headers HTTP, rate limiting, signatures webhooks
- [ ] `make test-security` passe à 100%
- [ ] Indexes PostgreSQL sur les colonnes fréquemment requêtées
- [ ] Lazy loading Vue, cache Redis sur les KPIs
- [ ] Sentry configuré + testé
- [ ] Papertrail configuré + 4 alertes actives
- [ ] UptimeRobot configuré + status page live
- [ ] Déploiement Heroku + RDS + Vercel fonctionnel
- [ ] Wildcard DNS `*.stayos.sn` sur Cloudflare
- [ ] Smoke tests en prod (login, réservation, paiement Paydunya test)
- [ ] Guide démarrage rapide utilisateur

**Livrable** : `https://demo.stayos.sn` accessible, stable, monitoré

---

## Récapitulatif

| Phase | Sprints | Durée | Résultat |
|---|---|---|---|
| Fondations | S1–S3 | 3 semaines | Infrastructure solide, auth, BDD complète |
| Core métier | S4–S7 | 4 semaines | PMS fonctionnel (réservation → facturation) |
| Opérations | S8–S9 | 2 semaines | Housekeeping + revenue management |
| Intelligence | S10–S11 | 2 semaines | Dashboard + temps réel |
| SaaS | S12–S13 | 2 semaines | Monétisation + supervision |
| Production | S14 | 1 semaine | Déploiement + sécurité finale |
| **Total** | **14 sprints** | **~14 semaines** | **App production-ready** |

---

## Évolutions futures (backlog hors-sprint)

Idées et besoins identifiés en cours de développement, à planifier
après les 14 sprints initiaux ou à intégrer dans un sprint dédié.

### Housekeeping — préférences client & offres
- **Client "Ne pas déranger" / refus du ménage quotidien** : permettre
  qu'un client décline la recouche. La génération quotidienne
  (generateDailyTasks) devra alors exclure les chambres dont le client
  / la réservation a opté pour "pas de ménage". Nécessite de stocker
  cette préférence (sur le Guest ou sur la Reservation) et de la
  prendre en compte dans la condition de génération.
- **Offre incitative "pas de recouche"** : proposer un avantage au
  client qui renonce au ménage de recouche (ex : boisson de bienvenue,
  réduction, points fidélité). Implique : un suivi du choix client par
  jour, une logique de récompense, et un impact sur la génération des
  tâches. Pertinent pour la RSE (économie d'eau/énergie) et la
  réduction des coûts de ménage.

### Module Staff & assignation des tâches
- **Module de gestion du personnel** : lister, créer, désactiver les
  comptes employés (StaffUser), gérer leurs rôles (MANAGER,
  RECEPTIONIST, ACCOUNTANT, HOUSEKEEPER). N'existe pas encore — les
  comptes sont créés via fixtures uniquement.
- **Endpoint de listing du staff** : GET /api/staff (filtrable par
  rôle, ex: ?role=HOUSEKEEPER) — prérequis pour toute UI d'assignation.
  À sécuriser par rôle (manager/réceptionniste).
- **UI d'assignation des tâches de ménage** : le backend est prêt
  (PATCH /api/housekeeping/tasks/{id}/assign, réservé MANAGER/
  RECEPTIONIST, + notif Mercure task.assigned). Manque le bouton
  "Assigner" dans la TaskCard du kanban (la prop isSupervisor est déjà
  câblée) et le menu de sélection du staff (nécessite l'endpoint
  ci-dessus).
- **Stratégie d'assignation à définir (réflexion produit)** : assigner
  manuellement tâche par tâche ? par zone/étage attribué à chaque
  agent ? automatiquement à la génération des tâches ? À trancher avant
  d'implémenter l'UI.
