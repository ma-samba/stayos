# StayOS — Plan de développement

## Workflow
Claude Code génère le code → l'utilisateur valide → Claude (chat) relit et vérifie la cohérence.
Pour chaque sprint : demander le prompt Claude Code dans le chat, puis soumettre le code généré pour relecture.

## Statut global
- Sprint courant : **Sprint 13bis — Complétion SuperAdmin & gestion personnel**
- Dernière mise à jour : 7 juin 2026
- Sprints terminés : 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13

---

## Vue d'ensemble — 15 sprints (~15 semaines)

```
Phase 1 — Fondations      (S1–S3)     : Infrastructure, Auth, BDD
Phase 2 — Core métier     (S4–S7)     : Chambres, Réservations, Clients, Facturation
Phase 3 — Opérations      (S8–S9)     : Housekeeping, Tarifs
Phase 4 — Intelligence    (S10–S11)   : Dashboard, Temps réel
Phase 5 — SaaS            (S12–S13bis): Abonnements, SuperAdmin, gestion personnel
Phase 6 — Production      (S14)       : Sécurité finale, déploiement
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
- [x] Makefile;jhdkjgdh — chargement `.env`, cibles psql robustes, commandes tenant

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

### ✅ Sprint 9 — Tarifs & promotions
**Objectif** : plans tarifaires, tarifs saisonniers, codes promo.

**Backend**
- [x] `PriceCalculator` isolé — calcul PAR NUIT (saison partielle), bcmath integral, arrondi FCFA
- [x] Saisonnier mixte : multiplicateur OU absolu, resolution par priorite
- [x] Promotions avancees : conditions par type de chambre/plan, plafond, min nuits, min montant, usage limite
- [x] `priceBreakdown` JSON sur Reservation (trace figee du calcul) + migration tenant
- [x] Branchement `ReservationEngine` (create/update) + consommation `usedCount` atomique a la creation
- [x] `InvoiceDraftService` lit `reservation.totalXof` (source de verite) — divergence facture/reservation corrigee
- [x] `RateController` — CRUD plans + saisonniers + endpoint POST /api/rates/quote (devis live, ne consomme pas)
- [x] `PromotionController` — CRUD promotions (soft delete)
- [x] `FeatureChecker` service — `revenue_management` sur ecriture des tarifs ; lecture et devis libres
- [x] `TenantAwarePurger` rendu auto-adaptatif (information_schema) — plus de liste de tables en dur

**Frontend**
- [x] `RatesView.vue` — 3 onglets (Plans / Saisonniers / Promos) avec tables, badges, actions
- [x] `RatePlanForm.vue` — creation/edition plan tarifaire
- [x] `SeasonalRateForm.vue` — formulaire avec label dynamique multiplicateur/absolu
- [x] `PromotionForm.vue` — formulaire avec conditions avancees repliables
- [x] Devis live dans `ReservationForm.vue` — code promo, appel quote debounce, recap prix avec saisonnier/promo
- [x] Garde feature : bandeau "Fonctionnalite Plan Pro" + boutons desactives si plan Starter

**Tests**
- [x] `PriceCalculatorTest` — 17 tests unitaires (saison, promo, mixte, arrondi, edge cases, robustesse time)
- [x] `ReservationPricingTest` — 5 tests integration (seasonal, promo usedCount, update recalcul, invoice coherence)
- [x] `RateControllerTest` — 9 tests fonctionnels (auth gating, CRUD, quote, cross-tenant, feature flag STARTER/PRO)
- [x] `PromotionControllerTest` — 5 tests fonctionnels (auth, create, doublon 409, soft delete, housekeeper 403)

**Livrable** : revenue management complet, 36 tests dedies, devis live dans le formulaire de reservation

---

### ✅ Sprint 10 — Dashboard & rapports
**Objectif** : tableau de bord temps réel, rapports d'occupation et revenus.

**Backend**
- [x] `KpiService` (src/Hotel/Analytics/) — KPIs du jour (dashboardToday) + rapport période (periodReport). Occupation, ADR HT, RevPAR HT, CA HT/TTC. Calcul PAR NUIT avec prorata sur la période, HT extrait du TTC (÷1.18), bcmath intégral.
- [x] Formules figées au standard hôtelier : occupation = nuits vendues / nuits disponibles ; ADR = revenu HT / nuits vendues ; RevPAR = revenu HT / nuits disponibles. Vérification croisée RevPAR = ADR × occupation testée.
- [x] `AnalyticsRepository` — réservations intersectant la période (exclut CANCELLED/NO_SHOW), comptage chambres actives non hors-service, arrivées/départs du jour.
- [x] DTOs immuables : `DashboardKpis`, `PeriodReport`, `DailyDataPoint` (série journalière).
- [x] `DashboardController` — GET /today (ROLE_ACCESS_BILLING), GET /report + GET /report/export (ROLE_ACCESS_REPORTS + feature advanced_reports). Validation des dates factorisée (parseDateRange).
- [x] `ReportExporter` — export CSV natif (BOM UTF-8, séparateur ;) + XLSX (PhpSpreadsheet ^2.2). Bascule via supportsXlsx().
- [x] RBAC : nouveau rôle ROLE_ACCESS_REPORTS (MANAGER + ACCOUNTANT, pas RECEPTIONIST) ajouté à la hiérarchie. /today ouvert à réception+manager+comptable, /report réservé manager+comptable.
- [x] Feature flag `advanced_reports` sur le plan Pro (rapports sur période et export derrière ce flag ; KPIs du jour libres).

**Frontend**
- [x] `DashboardView.vue` — page d'accueil unifiée : stat cards du jour + section Rapports (masquée si pas la feature) + sélecteur de période avec raccourcis + agrégats période + graphiques.
- [x] Graphiques Chart.js 4 / vue-chartjs 5 : `OccupancyChart` (courbe occupation/jour, axe 0-100%), `RevenueChart` (barres CA HT/jour), `SoldNightsChart` (nuits vendues/jour). RevPAR/ADR en cartes agrégées (pas de série journalière inventée).
- [x] `dashboard.service.ts` (today/report/exportReport blob) + `dashboard.store.ts` (Pinia setup).
- [x] Module 'dashboard' dans MODULE_ACCESS (MANAGER, RECEPTIONIST, ACCOUNTANT ; pas HOUSEKEEPER) ; dashboard = home par défaut pour ces rôles ; entrée sidebar "Tableau de bord" ; route /dashboard.
- [x] Étiquetage clair des périodes : "Activité du jour — {date}" vs bloc Rapports sur période. Carte "Occupation (vendu)" avec "{vendues}/{dispo} vendues" + carte distincte "Occupées (clients présents)".

**Tests**
- [x] `KpiServiceTest` — 22 tests (formules sur jeu de données connu, prorata période, intersection nuits, exclusion CANCELLED, vérification croisée RevPAR = ADR × occupation).
- [x] `DashboardControllerTest` — 11 tests fonctionnels (RBAC today/report, 403 housekeeper, 403 réceptionniste sur report, 403 PLAN_LIMIT vs ACCESS_DENIED distingués, 422 dates invalides, export CSV avec vérification headers).
- [x] Suite complète : 118 tests unitaires / 377 assertions verts.

**Ajouts notables (Sprint 10)**
- [x] Décision métier : ADR/RevPAR en HT (standard hôtelier, comparable aux benchmarks ; extraction depuis le TTC stocké).
- [x] Décision : occupation = "vendue" (nuits vendues/dispo), cohérente avec ADR/RevPAR. NB : diverge de l'intention initiale de services.md (occupation physique) — doc à resynchroniser.
- [x] Détail tarifaire (usage interne) sur la page facture, lu depuis priceBreakdown (option B) ; PDF/email client inchangés.
- [x] Réhydratation plan tarifaire + code promo à l'édition d'une réservation (depuis priceBreakdown) — corrige la perte de remise.
- [x] Sélecteurs plan tarifaire (résa) + type de chambre (plan) + onglet "Types de chambre" éditable + toggle CSS .toggle ajouté au design system.
- [x] Makefile : make fixtures / make db-reset utilisent --purger=tenant_aware (purger tenant-aware déjà en place).
- [x] Fixtures cohérentes : statuts de chambres dérivés des réservations CHECKED_IN (plus de OCCUPIED en dur) ; libellés "Occupation physique" (page Chambres) vs "Occupation (vendu)" (dashboard) harmonisés.

**Livrable** : dashboard opérationnel avec KPIs hôteliers justes (occupation, ADR HT, RevPAR HT, CA), rapports sur période avec graphiques et export CSV/XLSX, le tout sous contrôle RBAC + feature.

---

### ✅ Sprint 11 — Notifications temps réel
**Objectif** : tous les événements Mercure fonctionnent, centre de notifications frontend.

**Backend**
- [x] `MercurePublisher` (`Shared/Mercure/`) consolidé — publications namespacées `/hotel/{tenantId}/{event}`, tolérance aux pannes du hub via `catch` logger (WARNING désormais, plus muet).
- [x] Publications complétées : `reservation.cancelled` (`ReservationEngine::cancel`), `payment.received` déjà présent (`PaydunyaWebhookHandler` + `InvoiceService::recordPayment`) — pas de duplication.
- [x] `OperationalAlertService` (`Hotel/Notification/Domain/Service/`) — 3 alertes opérationnelles publiées via Mercure : `alert.arrivals_today`, `alert.late_checkout`, `alert.unassigned_tasks`. No-op si `count == 0` (pas de notification vide).
- [x] `PublishDailyAlertsHandler` (`Hotel/Notification/Application/MessageHandler/`) — itération multi-tenant hors HTTP : pour chaque tenant actif, `SET search_path` + `TenantContext::set`, restauration en `finally`, isolation des erreurs par tenant (un tenant en échec n'arrête pas les autres).
- [x] `PublishDailyAlertsCommand` (`stayos:alerts:daily`) — déclenchement manuel en attendant le scheduler Messenger du Sprint 12.
- [x] Endpoint `/api/staff` (`StaffController`) — listing read-only des membres du tenant, RBAC MANAGER+RECEPTIONIST, isolation tenant via `search_path`. Utilisé par le sélecteur d'assignation housekeeping. (NB : manque du Sprint 8 révélé pendant le Sprint 11, livré ici.)
- [x] `StaffUserRepository::findByRole` (accepte `ROLE_X` et `X`) + `StaffUser::getFullName`/`getRolesForApi` sérialisés (groupe `staff:read`).

**Frontend**
- [x] `notification-mapper.ts` — `fingerprintEvent` : routage par forme de payload pour une connexion Mercure multiplexée (Mercure n'expose pas le topic par message). 11 types d'événements mappés vers `Notification` typée (titre FR, sévérité, metadata pour navigation).
- [x] `mercure.service.ts` — méthode `subscribeMany(topics[], handler)` qui multiplexe plusieurs topics sur UNE EventSource. Résout la saturation HTTP/1.1 (limite 6 connexions/domaine vs 11+ topics naïvement abonnés). `subscribe()` délègue à `subscribeMany`.
- [x] `notifications.store.ts` — Pinia setup :
  - 3 EventSources au total (1 multiplexée pour 9 topics + 2 dédiées `checkin`/`checkout` — payloads identiques côté backend, indistinguables par fingerprint).
  - Filtrage à deux niveaux : par rôle (`NOTIFICATION_AUDIENCE` — matrice qui rôle voit quel type d'event) puis par utilisateur (`targetUserId` pour `task.assigned`, manager bypass conservé).
  - Anti-spam toasts : groupage des rafales du même type < 2 s.
  - Max 50 notifications, volatile par session (pas de `localStorage` — RGPD light).
- [x] `NotificationCenter.vue` — cloche dans la sidebar (visible même en mode réduit), badge unread, popover positionné pour ne pas sortir du viewport en mode collapsé.
- [x] `ToastContainer.vue` — toasts maison (pas de lib externe), durées différenciées par sévérité, `alert` persistant avec bouton fermer, clic → navigation vers la ressource.
- [x] `App.vue` — `connect()` au mount + `watch isAuthenticated`, `disconnect()` au logout (via `auth.store` + watch — redondance inoffensive notée au backlog).
- [x] Stores `rooms` / `reservations` / `housekeeping` / `dashboard` — méthodes `subscribeLive`/`unsubscribeLive` avec refcount + idempotence, patch local de l'état sans refetch (sauf cas où le payload est trop mince → refetch débouncé à 2 s). Les vues appellent `subscribeLive` en `onMounted`, `unsubscribeLive` en `onUnmounted`.
- [x] Helper `resolveTenantSlug()` — extraction du slug depuis le hostname (`savana.localhost` → `"savana"`), fallback sur `VITE_DEFAULT_TENANT_SLUG`. Permet le test multi-tenant en dev local.
- [x] `TaskCard.vue` — UI d'assignation des tâches ménage (bouton « Assigner » sur tâches non assignées, « Réassigner » sur tâches assignées, visible UNIQUEMENT pour MANAGER/RECEPTIONIST).

**Tests**
- [x] `OperationalAlertServiceTest` — 6 tests : comportements des 3 alertes (arrivées comptées, départs détectés, tâches non assignées, no-op si vide, exclusion des tâches assignées).
- [x] `StaffControllerTest` — 5 tests : RBAC manager/réceptionniste/housekeeper, listing avec/sans filtre rôle, isolation tenant (Villa Collines ne voit pas les staffs Savana).
- [x] Suite complète verte : 175 tests, 484 assertions, 0 failure, 1 skipped (Lexik rate limiter, voir backlog).

**Ajouts notables (Sprint 11)**
- [x] Découverte et fix de plusieurs régressions d'infra de test pré-existantes (`InvoiceServiceTest` depuis Sprint 7, fixtures test jamais chargées sur `stayos_test`) — `make test` désormais reproductible from scratch.
- [x] Découverte et fix du bug Mercure JWT < 256 bits (silently failing par catch muet) — leçon ajoutée au backlog : auditer les `catch \Throwable` silencieux du projet.
- [x] CORS dev : autorisation des sous-domaines `*.localhost:5173` via regex dans `nelmio_cors.yaml`, prod inchangée.

**Livrable** : système de notifications temps réel complet, filtrage à deux niveaux (rôle + utilisateur), isolation tenant prouvée. Alertes opérationnelles quotidiennes publiables via commande console (scheduler Messenger en Sprint 12).

---

### ✅ Sprint 12 — Abonnements & plans
**Objectif** : choisir un plan, payer l'abonnement, upgrader/downgrader.

**Backend**
- [x] `Platform/Subscription/Domain/Service/AbonnementService` —
  cycle de vie complet (createTrial 14j, upgrade, cancel, suspend,
  reactivate, renewAfterPayment, checkExpirations). Anti-spam
  relance par `lastNotificationType`.
- [x] `Platform/Subscription/Domain/Service/SaasInvoiceService` —
  séparation explicite de `Hotel/Billing/InvoiceService` métier.
  `generateForPeriod` (snapshot plan), `charge` (Paydunya),
  `markPaid` idempotent, `markFailed`. Méthode `buildTenantUrl`
  pour préfixer `returnUrl`/`cancelUrl` avec le sous-domaine
  tenant.
- [x] `Platform/Subscription/Domain/Service/SubscriptionEmailService` —
  extraction propre, 5 templates Twig (`trial-expiring-7d/1d`,
  `trial-expired`, `payment-link`, `payment-failed`,
  `payment-success`).
- [x] `Controller/Api/SubscriptionController` —
  `GET /subscription`, `/plans`, `/invoices` ; `POST /upgrade`,
  `/cancel`. RBAC `ROLE_MANAGER`. `computeUsage` (rooms/users en
  SQL brut sur le schema tenant courant).
- [x] `Platform/Subscription/Application/Command/CheckSubscriptionsCommand`
  (`stayos:subscriptions:check`) + handler MessageHandler.
- [x] `Platform/Subscription/Application/MessageHandler/CheckSubscriptionsHandler` —
  `SET search_path TO public` en début (handler hors HTTP),
  isolation try/catch par tenant.
- [x] Entité `SaasInvoice` dans `public.saas_invoices` (séparation
  données plateforme vs données hôtel) + enum `SaasInvoiceStatus`
  (DRAFT/PENDING/PAID/FAILED/CANCELLED).
- [x] Migration globale : table `saas_invoices` + colonnes
  `last_notification_sent_at`/`last_notification_type` sur
  `subscriptions`.
- [x] `PaydunyaWebhookHandler` étendu : routage `saas=1` ↔ métier,
  flux SaaS reproduit le pattern anti-fraude (secret, confirmation
  gateway, vérification montant, verrou pessimiste, idempotence
  dans le verrou).
- [x] `TenantMiddleware` — exemption `/api/subscription/*` du
  blocage tenant suspendu pour permettre au manager de
  régulariser.
- [x] `services.yaml` — `default_backend_url`/`default_frontend_url`
  passés en `%env(APP_BACKEND_URL)%` / `%env(FRONTEND_URL)%` (au
  lieu d'URLs hardcodées).

**Frontend**
- [x] `services/subscription.service.ts` — `getCurrent` (404 →
  null), `getPlans`, `upgrade`, `cancel`, `getInvoices`.
- [x] `services/tenant.ts` — helper `resolveTenantSlug`
  (sous-domaine → fallback `VITE_DEFAULT_TENANT_SLUG`).
- [x] `modules/subscription/views/SubscriptionView.vue` — bandeau
  d'état (trial/active/cancelled/suspended), carte plan actuel,
  stat cards utilisation (rooms/users avec seuil 80%),
  confirmation inline d'annulation (pattern `confirmingSkip`).
- [x] `modules/subscription/views/PricingView.vue` — cards
  comparatives, `recommendedPlanId` dynamique (plus cher
  non-Enterprise), plan actuel grisé, toggle Mensuel/Annuel
  (Annuel désactivé « Bientôt »).
- [x] `modules/subscription/components/UpgradeModal.vue` —
  distinction trial/active dans le message contextuel,
  consommation `checkoutUrl` pour la bascule Paydunya.
- [x] `modules/subscription/views/BillingHistoryView.vue` — stat
  cards + table SaasInvoice + bouton « Régler » sur
  pending/checkoutUrl.
- [x] `modules/subscription/views/AccountSuspendedView.vue` — page
  dédiée (sidebar masquée), bouton « J'ai régularisé, recharger »
  qui teste l'accès via `GET /dashboard/today`.
- [x] `modules/subscription/views/PaymentReturnView.vue` — retour
  Paydunya succès avec polling court (3 s × 10) si encore
  `pending` au moment du retour.
- [x] `modules/subscription/views/PaymentCancelView.vue` — page
  statique informative annulation.
- [x] `modules/subscription/feature-labels.ts` — mapping feature →
  libellé humanisé, réutilisable.
- [x] `services/api.service.ts` — intercepteur 402 → redirection
  `/account-suspended` (avec garde anti-boucle sur la route
  courante).
- [x] Routes `/subscription`, `/subscription/pricing`,
  `/subscription/invoices`, `/account-suspended` (`hideSidebar`),
  `/subscription/payment-return`, `/subscription/payment-cancel`.
- [x] `MODULE_ACCESS.subscription = ['MANAGER']` dans
  `auth.store`.
- [x] Sidebar — entrée « Abonnement » (`ti-crown`) MANAGER-only.
- [x] CORS dev : regex `*.localhost:5173` pour le multi-tenant
  local.
- [x] Helper auth — envoi du `X-Tenant-Slug` résolu dynamiquement
  depuis le hostname courant.

**Tests**
- [x] `AbonnementServiceTest` (unit) — `createTrial`, upgrade
  trial → active, cancel sans suspension immédiate,
  `checkExpirations` trial expiré, période active expirée,
  isolation erreurs par tenant.
- [x] `SubscriptionControllerTest` (functional) — RBAC manager vs
  autres rôles, isolation cross-tenant.
- [x] `PaydunyaWebhookHandlerTest` étendu —
  `testSaasIpnRoutesToSaasFlow`, `testSaasIpnInvalidSecret`,
  `testSaasIpnAmountMismatch`, `testSaasIpnIdempotent`.
- [x] Suite complète : 189 tests, 576 assertions, 0 failure,
  1 skipped (Lexik rate limiter du backlog).

**Ajouts notables (Sprint 12)** — découverts et corrigés pendant
les tests scénarisés
- [x] Trou IPN SaaS : la première implémentation créait un
  checkout avec callback `?saas=1` mais le webhook handler ne
  routait pas dessus → factures bloquées en PENDING éternellement.
  Correctif appliqué et testé bout en bout (ngrok + Paydunya
  sandbox).
- [x] 2 boutons « Passer en Pro » morts (Sprints 9 et 10) qui
  pointaient dans le vide en attendant `/subscription/pricing` :
  branchement + garde RBAC pour les non-managers.
- [x] Lacune 402 frontend : le `TenantMiddleware` bloquait bien
  les tenants suspendus avec 402, mais le front affichait juste
  une erreur générique sur chaque page. Création de
  `/account-suspended` + intercepteur axios dédié.
- [x] Paramètre `default_backend_url` hardcodé en dur dans
  `services.yaml` : passé en `%env(APP_BACKEND_URL)%` pour
  permettre le switch dev/ngrok/prod sans toucher au code. Idem
  `default_frontend_url`.
- [x] `returnUrl` Paydunya tenant-aware : la passerelle redirigeait
  sur `localhost:5173` au lieu de `{tenant}.localhost:5173` →
  impossible de retomber sur le bon schema. Méthode
  `buildTenantUrl` ajoutée à `SaasInvoiceService`.

**Livrable** : abonnements complets de bout en bout. Un manager
peut visualiser, comparer les plans, upgrader, annuler, voir son
historique. Le scheduler quotidien détecte trial expirant à J-7/J-1,
suspend les comptes en fin d'essai non régularisés, génère les
factures à échéance de période active, envoie le lien Paydunya,
suspend après `dueAt` si non payé. IPN Paydunya routé correctement
vers le flux SaaS, paiement → renouvellement automatique. UI
`account-suspended` avec sortie via régularisation.

---

### ✅ Sprint 13 — SuperAdmin & métriques plateforme
**Objectif** : interface opérateur pour gérer tous les tenants et monitorer la plateforme.

**Backend**
- [x] `Controller/SuperAdmin/SuperAdminController` — endpoints
  `GET /superadmin/tenants` (pagination + filtres status/plan/
  search), `GET /superadmin/tenants/{slug}` (détail +
  subscription complète + 5 dernières SaasInvoices),
  `POST /superadmin/tenants/{slug}/suspend` (body { reason? }),
  `POST /superadmin/tenants/{slug}/reactivate`,
  `GET /superadmin/metrics`. Protégé par `ROLE_SUPER_ADMIN` au
  niveau classe.
- [x] `Platform/Admin/Domain/Service/PlatformMetricsService` — MRR
  (bcmath, division /12 préparée pour annual V2), comptages par
  statut tenant, churn 30j (cancelled+suspended), newTenants 30j,
  planDistribution (active+trial). Pas de cache, calcul live à
  chaque requête.
- [x] DTO `Platform/Admin/Application/DTO/PlatformMetrics` (final
  readonly, `toArray()`).
- [x] `AbonnementService` enrichi : variantes
  `suspendForTenant(Tenant, ?reason)` /
  `reactivateForTenant(Tenant)` — destinées au SuperAdmin,
  travaillent sur le schema public sans nécessiter de
  `TenantContext`. Idempotence : 422 `BusinessRuleException` si
  déjà dans l'état cible.
- [x] `Platform/Admin/Application/Command/CreateSuperAdminCommand`
  (`stayos:superadmin:create`) — bootstrap d'un compte SuperAdmin
  (table `public.users`). Génère un mot de passe fort 16
  caractères si non fourni, garantit 1 caractère de chaque
  catégorie.
- [x] Fixture `DataFixtures/Platform/SuperAdminFixtures` — compte
  dev `admin@stayos.sn` / `superadmin123`.
- [x] Firewall `superadmin_login` + access_control public sur
  `/superadmin/auth/login` (`security.yaml`).
- [x] `TenantMiddleware::EXCLUDED_PREFIXES` enrichi avec
  `/superadmin` — les routes admin sont hors du flux multi-tenant.

**Frontend**
- [x] `services/superadmin.service.ts` — instance axios DÉDIÉE
  (séparée de `api.service` staff) : pas de `X-Tenant-Slug`,
  token distinct `superadmin_token`, redirect 401 vers
  `/superadmin/login` avec garde anti-boucle.
- [x] `stores/superadmin.store.ts` — Pinia setup, claims minimaux
  (username, roles, exp), vérification d'expiration JWT côté
  `isAuthenticated`.
- [x] `types/superadmin.ts` — `TenantSummary`, `TenantDetail`,
  `TenantSubscription`, `TenantInvoice`, `PlatformMetrics`,
  `TenantsListResponse`, `SuperAdminJwtClaims`.
- [x] `modules/superadmin/views/SuperAdminLoginView.vue` — card
  de login distincte du staff (badge « Back-office plateforme »),
  gestion 401 / 429, contrôle post-login que le compte a bien
  `ROLE_SUPER_ADMIN`.
- [x] `modules/superadmin/views/TenantsListView.vue` — stat cards
  (totaux via `getMetrics` silencieux), filtres
  status/plan/search avec debounce 300 ms, pagination
  10/20/50/100, badges status avec dot couleur cohérents.
- [x] `modules/superadmin/views/TenantDetailView.vue` — vue
  3 colonnes (Infos / Abonnement / Actions), confirmation inline
  suspend/reactivate (pattern `confirmingSkip` du Sprint 12),
  gestion 422 `BUSINESS_RULE`, flash message auto-effacé après
  4 s, refetch après action. Tableau des 5 dernières
  `SaasInvoice`.
- [x] `modules/superadmin/views/PlatformMetricsView.vue` — stat
  cards MRR + comptages, graphique Chart.js (Bar) sur
  `planDistribution`, lib réutilisée du Sprint 10 (pas de
  nouvelle dépendance).
- [x] Routes `/superadmin/login` + `/tenants` + `/tenants/:slug`
  + `/metrics` avec `meta.layout = 'superadmin'` et
  `meta.requiresSuperAdmin = true`. Guard isolé du staff (return
  tôt dans `beforeEach`).
- [x] `App.vue` — layout conditionnel SuperAdmin : header
  horizontal sans sidebar staff, pas de connexion Mercure, pas
  d'`EventSource`. Login plein écran. Le `watch` sur
  `auth.isAuthenticated` sort tôt si layout SuperAdmin (pas de
  connect/disconnect Mercure erroné).
- [x] `vite.config.ts` — proxy `/superadmin` → nginx avec
  `bypass` pour distinguer requêtes HTML (SPA fallback Vite)
  des requêtes JSON (proxifiées vers le backend), évite la
  collision routing Vue Router ↔ API sur le même préfixe.

**Tests**
- [x] `tests/Functional/SuperAdmin/SuperAdminTest` — 8 tests
  (42 assertions) :
  - `testStaffUserCannotAccessSuperadmin` (401/403)
  - `testSuperAdminCanLoginAndListTenants`
  - `testSuperAdminCanFilterByStatus`
  - `testSuperAdminCanSuspendTenant` (cohérence
    tenant+subscription)
  - `testSuspendedTenantReturns402` — test critique du
    livrable, couplage Sprint 12 ↔ Sprint 13
  - `testSuperAdminCanReactivateTenant`
  - `testSuspendIsIdempotent` (422 `BUSINESS_RULE`)
  - `testMetricsEndpointReturnsExpectedShape`
- [x] `setUp` + `tearDown` restaurent Villa Collines `ACTIVE`
  pour pas polluer les fixtures partagées.
- [x] Suite globale : 189 tests verts (+ 8 `SuperAdminTest`
  silencieusement skippés par défaut via `@group integration`
  exclu — point au backlog).

**Ajouts notables (Sprint 13)** — découvertes et décisions
- [x] Découverte que l'archi SuperAdmin était **partiellement
  préparée depuis le Sprint 2** : entité `User` dans
  `public.users`, firewall `superadmin` + role hierarchy
  `ROLE_SUPER_ADMIN: [ROLE_ADMIN]`, `JWTCreatedListener` qui
  filtre déjà les non-`StaffUser`. Sprint 13 a juste « branché »
  ce qui existait → périmètre 13a très ramassé.
- [x] Choix d'architecture documenté : variantes
  `suspendForTenant` / `reactivateForTenant` au lieu de
  manipuler `TenantContext::set + clear`. Plus propre
  conceptuellement (le SuperAdmin ne touche jamais aux schemas
  `hotel_{uuid}`, uniquement `public.tenants` +
  `public.subscriptions`).
- [x] Incohérence vocabulaire identifiée : l'enum
  `TenantStatus` utilise `CHURNED`, la string
  `Subscription.status` utilise `cancelled`, le DTO
  `PlatformMetrics` expose `cancelledTenantsCount` qui lit
  `CHURNED`. Pas un bug mais une dette à aligner — notée au
  backlog « Leçons d'architecture ».
- [x] Bug pré-existant `APP_BACKEND_URL` absent en env test :
  bloquait l'instanciation de `SaasInvoiceService` (injecté via
  `AbonnementService`) dans tout test fonctionnel — invisible
  jusque-là car les tests Sprint 12 étaient déjà `@group
  integration` exclus. Ajouté à `.env.test` +
  `phpunit.xml.dist`.
- [x] Découverte au moment d'utiliser le SuperAdmin : périmètre
  MVP livré (visualiser + suspend/reactivate + métriques)
  **insuffisant pour un vrai opérateur SaaS**. Manques
  identifiés et formalisés dans le Sprint 13bis dédié.

**Livrable** : back-office plateforme MVP opérationnel. Un
SuperAdmin peut se connecter via une UI dédiée (sidebar masquée,
pas de Mercure), lister/filtrer/consulter les tenants, suspendre
manuellement (en plus de la suspension auto du scheduler Sprint
12) et réactiver, voir les métriques plateforme. Couplage Sprint
12 ↔ Sprint 13 prouvé : la suspension SuperAdmin redirige bien
le manager du tenant suspendu vers `/account-suspended`.

---

### ⬜ Sprint 13bis — Complétion SuperAdmin & gestion personnel
**Objectif** : combler les manques production-ready côté
back-office SuperAdmin ET livrer la gestion du personnel hôtel
(manager invite / édite / désactive ses employés).

⚠️ Deux périmètres distincts dans le même sprint car ils sont
prérequis communs au go-live :
- Gestion personnel : pour le MANAGER de chaque hôtel (ses
  réceptionnistes, comptables, femmes de chambre). Sans ça, un
  client en prod n'a aucun moyen d'inviter son équipe.
- Complétion SuperAdmin : pour les opérateurs StayOS (créer un
  tenant manuellement, éditer ses paramètres, observer
  l'historique).

**Backend — Gestion du personnel (côté tenant)**
- [ ] Compléter `StaffController` (aujourd'hui read-only) :
  `POST` création, `PUT` édition, `DELETE` soft désactivation
  (`StaffUser.active = false` plutôt que delete physique).
  RBAC : MANAGER uniquement.
- [ ] Entité `StaffInvitation` (table dans schema tenant) +
  enum `InvitationStatus` (`PENDING` / `ACCEPTED` / `EXPIRED`).
- [ ] `StaffInvitationService` : générer un token unique signé
  (HS256 avec `APP_SECRET`, durée 7 j), envoyer email Mailjet
  avec lien de finalisation, accepter (création du `StaffUser`
  réel avec password choisi par l'invité).
- [ ] `StaffInvitationController` :
  - `POST /api/staff/invitations` — émettre une invitation
    (RBAC MANAGER) + check
    `SubscriptionLimitChecker::assertCanAddUser()` (cf. backlog
    « Contrôle d'abonnement »).
  - `GET /api/staff/invitations/{token}` — public, récupère
    les infos publiques de l'invitation (email, rôle proposé,
    hôtel).
  - `POST /api/staff/invitations/{token}/accept` — public,
    body `{ password }`, crée le `StaffUser` dans le schema
    tenant, marque l'invitation `ACCEPTED`.
- [ ] `EmailService::sendStaffInvitation()` — template Twig
  avec lien `https://{tenant}.stayos.sn/invitation/{token}`.

**Backend — Complétion SuperAdmin**
- [ ] `POST /superadmin/tenants` — création manuelle d'un
  tenant par le SuperAdmin (réutilise
  `OnboardingService::provision()` MAIS bypass OTP : le
  SuperAdmin a déjà vérifié l'identité). Body : `hotel_name`,
  `slug`, `manager_email`, `manager_name`, `plan` (STARTER par
  défaut). Génère un mot de passe temporaire pour le manager,
  l'envoie par email avec instruction de le changer au premier
  login.
- [ ] `PATCH /superadmin/tenants/{slug}` — édition limitée des
  paramètres tenant : `name`, `timezone`, `country`, `currency`,
  `settings` (JSON). PAS de modification du `schema_name` ou
  de l'id.
- [ ] `POST /superadmin/tenants/{slug}/subscription` — forcer
  un changement de plan / dates de subscription (geste
  commercial). Body : `plan_id`, `current_period_end?`,
  `reason` (audit). Crée une entrée `AuditLog` dédiée.
- [ ] `GET /superadmin/audit` — log des actions SuperAdmin
  des 30 derniers jours (lecture seule). Stockage dans une
  nouvelle table `public.superadmin_audit_log`.
- [ ] `Platform/Admin/Domain/Service/SuperAdminAuditService` +
  entité `SuperAdminAuditLog` (qui, quand, sur quel tenant,
  action, payload, IP).

**Frontend**
- [ ] Module **Personnel** (manager-only) :
  - `modules/staff/views/StaffListView.vue` — liste des
    employés actifs + invitations en attente. Filtres par
    rôle, bouton « Inviter un employé ».
  - `modules/staff/components/InviteStaffModal.vue` —
    formulaire email + rôle à attribuer + bouton envoyer.
  - `modules/staff/views/StaffDetailView.vue` — édition
    rôle, désactivation, réactivation.
  - `modules/staff/views/AcceptInvitationView.vue` — page
    publique, formulaire de création du compte (password +
    confirm), redirection vers login après.
  - `MODULE_ACCESS.staff = ['MANAGER']` dans `auth.store` +
    entrée sidebar « Personnel » (`ti-users-group`).
- [ ] Module **SuperAdmin** étendu :
  - `modules/superadmin/views/CreateTenantView.vue` —
    formulaire création tenant.
  - `modules/superadmin/views/EditTenantView.vue` (ou dans
    `TenantDetailView` en mode édition) — édition des
    paramètres.
  - `modules/superadmin/views/SuperAdminAuditView.vue` —
    journal d'audit.

**Tests**
- [ ] `StaffInvitationServiceTest` — émission, validation
  token, expiration, double accept, isolation tenant.
- [ ] `StaffControllerTest` étendu — CRUD complet RBAC.
- [ ] `SuperAdminTest` étendu — création tenant, édition
  paramètres, audit log.

**Livrable** : un manager hôtel peut inviter son équipe par
email, gérer les rôles, désactiver. Un SuperAdmin peut créer un
tenant manuellement, éditer ses paramètres, et voir l'historique
des actions admin. Production-ready côté gestion.

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
| SaaS (suite) | S13bis | 1 semaine | Complétion opérationnelle (personnel + back-office) |
| Production | S14 | 1 semaine | Déploiement + sécurité finale |
| **Total** | **15 sprints** | **~15 semaines** | **App production-ready** |

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
- ~~**Endpoint de listing du staff**~~ : livré au Sprint 11 (GET
  `/api/staff?role=ROLE_X`, RBAC MANAGER+RECEPTIONIST). Lecture seule
  pour l'instant.
- ~~**UI d'assignation des tâches de ménage**~~ : livré au Sprint 11
  (bouton « Assigner »/« Réassigner » dans `TaskCard.vue`, popover
  avec `<select>` lazy-loaded depuis `/api/staff`).
- ~~**Module de gestion du personnel (vraie RH)**~~ : intégré au
  **Sprint 13bis** (gestion complète des `StaffUser`,
  invitations email, RBAC, désactivation).
- **Stratégie d'assignation à définir (réflexion produit)** : assigner
  manuellement tâche par tâche (livré) ? par zone/étage attribué à
  chaque agent ? automatiquement à la génération des tâches ? La V1
  manuelle est en place — affiner si l'usage révèle le besoin.

### Tarification — affinements reportes
- **Promo conservee a la modification de reservation** : aujourd'hui un
  `update()` de reservation sans promoCode/ratePlanId recalcule SANS la
  promo (la remise saute si on change juste les dates). Comportement
  explicite choisi pour le Sprint 9. Le Sprint 10 a partiellement
  resolu cas (rehydratation depuis `priceBreakdown` dans le formulaire),
  mais le cas "modification des dates sans repasser par le formulaire"
  reste expose. Options etudiees : (A) garder tel quel — pedagogique ;
  (B) memoriser la promo/le plan sur la reservation et les reappliquer
  au recalcul ; (C) avertir l'utilisateur avant recalcul.
  Recommandation : (B), avec une migration tenant legere pour
  persister `ratePlanId` / `promoCode` sur `reservations`.
- **Ligne de facture detaillee (client)** : `InvoiceDraftService`
  genere une ligne unique au total reel. Le detail tarifaire est
  visible en interne sur la page facture (option B livree au Sprint 10,
  lu depuis `reservation.priceBreakdown`). Reste a faire : decomposer
  les lignes de la facture CLIENT (tarif de base, ajustement
  saisonnier, remise promo) pour une lecture claire sur le PDF/email.
  Implique une reflexion fiscale (traitement de la remise sur la TVA)
  a valider avec un comptable local avant implementation.
- **Feature-gating complet — PARTIEL au Sprint 12** : le
  `FeatureChecker` couvre désormais 4 endpoints (2 écriture tarifs
  `revenue_management`, 2 rapports `advanced_reports`) via appels
  manuels `assertEnabled` au début de chaque controller.
  La généralisation via attribut/voter Symfony reste à faire,
  ainsi que `FeatureGuardTest`. Voir la nouvelle section
  « Contrôle d'abonnement & feature-gating » qui détaille le
  travail restant.
- **Ciblage type de chambre pour les tarifs saisonniers** : comme les
  plans, `SeasonalRate` a un champ `roomType` nullable cote backend mais
  `SeasonalRateForm` ne l'expose pas (toutes les saisons visent "tous
  les types" via l'UI). Ajouter le selecteur si le besoin d'une saison
  specifique a un type emerge.
- **Conditions avancees des promotions dans l'UI** : `PromotionDTO`
  accepte `applicableRoomTypeIds` / `applicableRatePlanIds` (restriction
  d'une promo a certains types/plans), mais `PromotionForm` ne les
  expose pas encore. A ajouter si le besoin de ciblage fin se confirme.

### Contrôle d'abonnement & feature-gating
- **Limites quantitatives des plans (priorité haute, avant prod)** :
  les compteurs `maxRooms` et `maxUsers` des plans sont aujourd'hui
  PUREMENT INFORMATIFS. `SubscriptionController::computeUsage` les
  expose dans `SubscriptionView` mais aucun garde-fou n'empêche un
  compte Starter de dépasser. À implémenter : check dans
  `RoomService::create` via un nouveau
  `SubscriptionLimitChecker::assertCanAddRoom()` (lecture
  subscription + count des rooms actives), check équivalent dans
  la future création d'utilisateurs staff (POST `/api/staff` qui
  n'existe pas encore — l'endpoint actuel est read-only). Côté
  front : griser le bouton « Nouvelle chambre » + tooltip
  explicatif si à la limite. À traiter avant Sprint 14.
- **Feature-gating via voter Symfony (priorité moyenne, Sprint 14)** :
  aujourd'hui les features sont gardées par appels manuels à
  `featureChecker->assertEnabled('feature_name')` au début des
  endpoints concernés (2 endpoints écriture tarifs
  `revenue_management`, 2 endpoints rapports `advanced_reports`).
  Risque : un futur endpoint ajouté sans cet appel ne sera pas
  gardé et le bug peut rester invisible des mois (même mécanique
  que le `catch` muet Mercure du Sprint 11). À implémenter :
  attribut `#[IsGranted('FEATURE', 'revenue_management')]` + voter
  dédié qui appelle `FeatureChecker::isEnabled`. Couvrir par un
  `FeatureGuardTest` global qui vérifie que tous les endpoints
  critiques retournent 422 `PLAN_LIMIT` pour un Starter (test
  prévu dans le prompt Sprint 12 mais non livré).
- **Features annoncées sans contenu (priorité basse, décision UX
  produit)** : les fixtures déclarent `channel_manager`,
  `multi_property`, `api_access` dans le plan Pro. Ces features
  n'existent pas encore dans le code → promesse vide à terme.
  Trois options à arbitrer : (A) retirer ces clés des fixtures
  (honnête) ; (B) garder mais badger « Bientôt disponible » dans
  `feature-labels.ts` et `PricingView` (transparent) ; (C) laisser
  tel quel (risqué).

### Dashboard & rapports — enrichissements
- **Comparaison vs periode precedente** : afficher la variation % des
  KPIs par rapport a la meme periode precedente (ex : +12% occupation
  vs mois dernier). Necessite un double appel ou un calcul backend
  dedie.
- **Repartition par source / type de chambre** : camemberts ou barres
  empilees montrant la ventilation des reservations par source
  (direct, Booking, Airbnb...) et par type de chambre sur la periode.
- **RevPAR par jour** : aujourd'hui RevPAR n'est affiché qu'en carte
  agrégée (pas de série journalière). Pour un graphe RevPAR/jour il
  faudrait exposer le nombre de chambres disponibles par jour dans
  la série (actuellement non transmis par l'API).
- **Cache Redis sur les KPIs** : prevu Sprint 14 — rappel. Les
  rapports sur de longues périodes (~365 jours) itèrent sur chaque
  jour en PHP ; un cache courte durée (5 min) réduirait la charge.

### Statuts de chambre — machine a etats
- Le statut de chambre est aujourd'hui librement modifiable (PATCH
  /rooms/{id}/status accepte toute valeur). Envisager des garde-fous
  de transition : interdire/avertir si on marque AVAILABLE une chambre
  avec un client CHECKED_IN, ou OCCUPIED sans reservation active.
  Definir la matrice de transitions autorisees. Impact : coherence
  renforcee entre statut physique et reservations.

### Notifications temps réel — raffinements
- **Anti-spam toasts, cas limite rafale 4+** : à partir de la 4e
  notification d'une rafale identique en < 2 s, le compteur du toast
  groupé se réinitialise au lieu de continuer à monter. Cas rare (ex :
  5 tâches assignées simultanément en début de service), à raffiner
  si l'usage le révèle. Voir `notifications.store.ts` →
  `shouldGroupBurst` + `pushToast`.
- **Double `disconnect()` au logout** : `notifications.disconnect()`
  est appelé deux fois — depuis le `watch` sur `isAuthenticated` dans
  `App.vue` ET depuis `auth.store.logout()`. Idempotent (refcount =>
  no-op si déjà fermé), donc inoffensif, mais un seul chemin
  suffirait. À nettoyer pour la lisibilité.
- **Refetch reservations + filtre actif** : sur `reservation.created`,
  `reservations.store` redéclenche un fetch avec le dernier filtre
  appliqué (`lastFetchParams`). Si la vue affichait un filtre par
  statut différent de `confirmed`, la nouvelle réservation peut ne
  pas apparaître malgré l'event reçu — techniquement correct mais
  peut frustrer l'utilisateur. Options : (A) toujours refetch sans
  filtre puis filtrer côté client, (B) notifier la liste cachée
  ("1 nouvelle réservation hors filtre — cliquer pour voir").
- **Distinguer les events `reservation.checkin` / `reservation.checkout`
  côté backend** : leurs payloads sont actuellement identiques, ce qui
  empêche le routage par `fingerprintEvent` et oblige à 2 EventSources
  dédiées (vs multiplex pour les 9 autres). Ajouter un champ `_event`
  au payload publié par `MercurePublisher` permettrait de tout
  multiplexer sur une seule EventSource — coût modeste, gain :
  -2 connexions.

### Infrastructure de test & qualité
- **Bug Lexik rate limiter sur login après 5 tentatives** : comportement
  anormal reproduit en environnement de développement, test
  `testLoginRateLimitAfterFiveAttempts` skippé avec message explicite.
  À investiguer : config Lexik, version du bundle, ou intégration
  avec le rate limiter Symfony. Bloque la couverture du flux
  bruteforce login en CI.
- **Scripts npm racine cassés (frontend/)** : `npm run build` et
  `npm run lint` échouent par absence de `tsconfig.json` et
  `eslint.config.js` à la racine `frontend/`. Le type-check ciblé
  (`tsc --strict` sur les fichiers modifiés) passe et `vite build`
  fonctionne, donc le code est sain — mais la CI/déploiement
  nécessitera ces scripts opérationnels. À remettre en état avant
  Sprint 14 (production).
- **Suite complète à chaque clôture de sprint** : les régressions
  `InvoiceServiceTest` (Sprint 7) et le 404 fonctionnel (fixtures
  test jamais chargées) ont été révélés tard parce que `make test`
  complet n'avait pas tourné régulièrement. Établir un réflexe de
  fin de sprint : suite complète verte AVANT le commit de clôture.
  Idéalement, automatiser via un pre-commit hook ou une étape CI au
  Sprint 14.
- **Auditer les `catch (\Throwable)` silencieux** : le bug Mercure
  JWT < 256 bits a été masqué pendant un sprint entier par un catch
  muet dans `MercurePublisher`. Le fix au Sprint 11 a transformé ce
  catch en `WARNING` loggé, mais d'autres opérations critiques
  (paiement, email, upload Uploadcare, génération PDF, IPN Paydunya,
  etc.) ont peut-être des catch équivalents qui avalent les
  exceptions sans tracer. Une demi-heure de revue qui peut éviter le
  prochain bug fantôme — à prévoir avant le Sprint 14.
- **Tests de retour Paydunya non couverts (Sprint 12)** : les pages
  `PaymentReturnView` et `PaymentCancelView`, ainsi que le polling
  `pending`, ne sont pas couverts par des tests automatisés. Le
  paiement Paydunya bout en bout exige Paydunya sandbox + ngrok,
  inadapté à la CI. Envisager une commande de test
  `stayos:test:paydunya-ipn` qui mocke `PaydunyaService` et envoie
  un IPN simulé directement sur le webhook — utile pour la CI et
  pour valider la chaîne SaaS en dev sans Paydunya réel.
- **Audit des paramètres Symfony hardcodés** : `services.yaml`
  contenait `default_backend_url: 'http://localhost:8080'` en dur
  au lieu de `%env(APP_BACKEND_URL)%`. Auditer tous les
  `parameters:` de `services.yaml` et des autres yaml pour
  vérifier qu'aucune URL, secret ou config sensible n'est figée —
  tout doit être résolu via `%env(VAR)%` avec un défaut explicite
  si besoin. Sinon les variables d'env ne servent à rien et la
  prod utilisera des valeurs de dev.
- **Tests `@group integration` silencieusement skippés
  (Sprint 13)** : les 8 tests `SuperAdminTest` sont marqués
  `@group integration` parce qu'ils dépendent des fixtures, et
  `phpunit.xml.dist` exclut ce groupe par défaut. Donc
  `make test` retourne « 189 tests verts » sans les exécuter,
  masquant des régressions potentielles. Trois options à
  arbitrer :
  (A) retirer l'annotation `@group integration` (les tests
  SuperAdmin tournent en ~4 s, acceptable) ;
  (B) garder l'annotation et ajouter une cible Makefile
  `make test-integration` qui force `--group integration`,
  intégrée à un `make test-all` ;
  (C) revoir tous les tests `@group integration` du projet pour
  décider lesquels doivent vraiment être exclus par défaut.
  À traiter au Sprint 14 (production-ready) en même temps que la
  CI.

#### Deprecations PHP/Symfony (priorité basse)
- **`StaffUser::eraseCredentials()`** à annoter `#[\Deprecated]` —
  Symfony 7.3+ recommande l'attribut. 5 minutes, à faire à la
  prochaine touche du fichier.
- **Sprint d'upgrade Doctrine ORM 4.0** : les deprecations
  Doctrine représentent ~121 occurrences au `make test`. Migration
  possible quand `doctrine/doctrine-bundle` aura pris en charge
  ORM 4.0. Probablement plusieurs mois dans le futur.

### Mercure — durcissement production
- **Abonnements anonymes en dev, à durcir en prod** : la config
  Mercure dev (`backend/compose.yaml`) active la directive Caddy
  `anonymous` et `cors_origins http://localhost:5173 ...` pour que
  le front s'abonne sans JWT. L'isolation cross-tenant repose alors
  uniquement sur l'imprévisibilité de l'UUID tenant dans le topic
  (`/hotel/{uuid}/...`). Acceptable en dev, **insuffisant en prod** :
  un UUID fuité (logs, screenshot, devtools) permet à un tiers de
  s'abonner aux events d'un hôtel.
- **Plan prod** :
  - Générer un JWT subscriber par session staff (claim `mercure.subscribe`
    listant uniquement les topics du tenant courant) — service
    Symfony à ajouter, exposé via `GET /api/auth/mercure-token`.
  - Frontend : passer ce JWT à `EventSource` via `withCredentials: true`
    et un cookie ou un query param signé (selon politique CORS).
  - `cors_origins` restreint au domaine de prod
    (`https://*.stayos.sn`).
  - TLS réel (Caddy auto-cert Let's Encrypt en HTTPS, ce qui est le
    comportement par défaut dunglas/mercure si on enlève le
    `SERVER_NAME: ':80'`).
  - Retirer `publish_origins *` et restreindre au domaine Heroku/backend.
- **À planifier au Sprint 14 (production-ready)** en même temps que le
  durcissement sécurité général (headers HTTP, signatures webhooks).

### Documentation à resynchroniser
- **services.md vs code réel** : décrit un `DashboardService` alors
  que le code livré est `KpiService` (`src/Hotel/Analytics/`), un
  `ReportController` alors que c'est `DashboardController`, et
  définit l'occupation comme physique (chambres occupées / totales)
  alors que le code calcule l'occupation vendue (nuits vendues /
  nuits disponibles) — choix assumé au Sprint 10 pour cohérence
  ADR/RevPAR. Réécrire la section pour refléter le code livré.
- **fixtures.md vs code réel** : décrit une structure de fixtures
  (`RoomFixtures`, `RoomTypeFixtures`, `FloorFixtures`,
  `SuperAdminFixtures` séparés) qui ne correspond pas au code (tout
  regroupé dans `HotelDataFixtures`, `ReservationFixtures`, etc.).
  Aligner sur la réalité du code.
- **deploy.md** : à compléter avec la checklist Heroku réelle
  (Config Vars à définir, équivalences `.env` ↔ Config Vars,
  add-ons Heroku Postgres/Redis, deuxième app Mercure, Vercel pour
  le frontend). Une vraie checklist plutôt que des généralités. À
  faire en parallèle du Sprint 14.

### Leçons d'architecture (réflexes à intégrer)
Principes transverses tirés des découvertes en cours de sprint —
ce ne sont pas des tickets mais des réflexes à appliquer.

- **Cohérence destination ↔ point de départ** : à chaque sprint qui
  livre une nouvelle « destination » (page, endpoint, feature),
  passer une revue rapide des « points de départ » dans le code
  qui auraient dû y pointer (CTA « Passer en Pro » morts depuis
  les Sprints 9 et 10, branchés seulement au Sprint 12). 30 min
  de revue systématique qui évitent une demi-douzaine de boutons
  morts.
- **Cohérence callbacks externes** : à chaque fois qu'on configure
  un `returnUrl` / `cancelUrl` côté backend qui sera atteint par
  un service tiers (Paydunya, mais aussi Mailjet, Stripe, etc.),
  vérifier que la route correspondante existe côté front ET
  qu'elle gère correctement le contexte tenant. Les pages
  `payment-return`/`cancel` manquaient au Sprint 12 — découvertes
  uniquement en test manuel.
- **Anti-spam d'emails à enrichir si séquence change** : l'anti-
  spam `markNotification` actuel compare uniquement le dernier
  type envoyé. Acceptable V1, fragile si on ajoute des étapes de
  relance (ex : J-3 entre J-7 et J-1) ou si on revient à un type
  antérieur. Refactor à prévoir le jour où la séquence s'enrichit.
- **Race condition théorique sur `generateNextNumber`** :
  `SaasInvoiceRepository::generateNextNumber` fait un `COUNT(*)`
  non transactionnel pour produire `SAAS-YYYY-NNNNN`. Probabilité
  quasi nulle avec un scheduler/jour. À durcir si on déploie
  plusieurs workers en parallèle (verrou ou séquence Postgres).
- **Vocabulaire enum cohérent dès le départ** : `TenantStatus`
  utilise `CHURNED`, `Subscription.status` utilise `cancelled`,
  et le DTO `PlatformMetrics` expose `cancelledTenantsCount` qui
  lit `CHURNED`. Incohérence qui force le frontend à mapper
  manuellement. Réflexe à prendre : quand deux concepts désignent
  la même réalité métier, choisir UN seul terme et l'imposer
  partout (enum, string DB, DTO, type front). À aligner au
  Sprint 14.
- **Préparation d'archi vs livraison effective** : le Sprint 13
  a découvert que l'archi SuperAdmin était préparée depuis le
  Sprint 2 (entité `User`, firewall, rôle dans la hiérarchie)
  mais jamais « branchée ». Idéal pour réduire la dette
  technique d'un coup, mais peut aussi créer de la confusion (du
  code mort qui ne sert à rien pendant N sprints). Réflexe à
  appliquer : documenter explicitement dans le code les
  composants « préparés pour un futur sprint » avec un
  commentaire pointant vers le sprint cible, pour qu'on retrouve
  facilement.
