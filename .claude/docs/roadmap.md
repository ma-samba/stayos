# StayOS — Plan de développement

## Workflow
Claude Code génère le code → l'utilisateur valide → Claude (chat) relit et vérifie la cohérence.
Pour chaque sprint : demander le prompt Claude Code dans le chat, puis soumettre le code généré pour relecture.

## Statut global
- Sprint courant : **Sprint 13quinquies — Corrections financières (no-show, refund, politique d'annulation)**
- Dernière mise à jour : 9 juin 2026
- Sprints terminés : 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 13bis, 13ter, 13quater

---

## Vue d'ensemble — 17 sprints (~17 semaines)

```
Phase 1 — Fondations      (S1–S3)            : Infrastructure, Auth, BDD
Phase 2 — Core métier     (S4–S7)            : Chambres, Réservations, Clients, Facturation
Phase 3 — Opérations      (S8–S9)            : Housekeeping, Tarifs
Phase 4 — Intelligence    (S10–S11)          : Dashboard, Temps réel
Phase 5 — SaaS            (S12–S13quinquies) : Abonnements, SuperAdmin, gestion personnel,
                                               config hôtel, night audit, corrections financières
Phase 6 — Production      (S14)              : Sécurité finale, déploiement
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

### ✅ Sprint 13bis — Complétion SuperAdmin & gestion personnel
**Objectif** : combler les manques production-ready côté
back-office SuperAdmin ET livrer la gestion du personnel hôtel
(manager invite / édite / désactive ses employés).

⚠️ Sprint livré en 3 étapes itératives — la structure ci-dessous
reflète cette réalité, utile pour relire l'historique :
1. **13bis-A** : gestion personnel + `SubscriptionLimitChecker`.
2. **Correctifs 13bis-A** : audit log staff, `lastLoginAt`,
   journal d'activité par employé.
3. **13bis-B** : complétion SuperAdmin (création tenant manuelle,
   édition, `forcePlan`, audit log dédié).

**Backend — Gestion personnel (côté tenant) [13bis-A]**
- [x] Entité `StaffInvitation` + enum `InvitationStatus`
  (PENDING / ACCEPTED / EXPIRED / REVOKED). Table tenant avec
  index unique (email, status PENDING) et index sur tokenHash.
- [x] Migration tenant `Version20260607000000AddStaffInvitations`
  enregistrée dans `TenantMigrationRegistry`.
- [x] `StaffInvitationService` — token clair `random_bytes(32)`
  + bin2hex (64 chars, 256 bits d'entropie), hash SHA-256 stocké
  en BDD. Méthodes invite / getByToken / accept / revoke avec
  marquage défensif EXPIRED dans `getByToken`.
- [x] `SubscriptionLimitChecker::assertCanAddUser()` — compte
  actifs + pending invitations, message d'erreur explicite avec
  nom du plan et limite chiffrée. Plan ENTERPRISE (null) bypass.
- [x] `StaffController` étendu : POST création directe (avec
  password temporaire 16 chars retourné UNE FOIS), PUT édition
  (email gelé), POST reset-password, DELETE soft (active=false,
  self-deactivation refusée 422), POST reactivate (avec re-check
  limite plan).
- [x] `StaffInvitationController` (manager) : POST invite,
  GET list, POST revoke.
- [x] `StaffInvitationPublicController` (firewall séparé) :
  GET infos publiques par token, POST accept avec password
  choisi. Le tenant est résolu via `X-Tenant-Slug` header.
- [x] Firewall `public_invitations` dans `security.yaml`
  (pattern `^/public/invitations`, `security: false`),
  positionné avant le firewall `api`.
- [x] `EmailService::sendStaffInvitation()` + template Twig.
- [x] `Shared/Url/TenantUrlBuilder` — helper partagé
  (factorisation amorcée, à terminer au Sprint 14).

**Backend — Complétion SuperAdmin [13bis-B]**
- [x] Migration global
  `Version20260607100000CreateSuperAdminAuditLog` — table
  `public.superadmin_audit_log` avec 3 indexes (tenant_slug,
  actor_email, created_at DESC).
- [x] Entité `SuperAdminAuditLog` + repository avec `paginate()`.
- [x] `SuperAdminAuditService::log(actor, tenant?, action,
  payload?, request?)` — capture automatique IP/UA depuis la
  Request.
- [x] `OnboardingService::provision(data, initialStatus)` —
  variante sans OTP de `register()`, retourne `{tenant, password}`
  avec password temporaire 16 chars. Choix `'trial'` ou `'active'`
  comme statut initial. Pattern try/finally identique à `register`
  pour `SET search_path`.
- [x] `AbonnementService::createActive(Tenant, Plan)` — crée
  subscription active +30j, refuse si déjà active.
- [x] `AbonnementService::forcePlan(Tenant, Plan, ?newPeriodEnd)`
  — change plan + dates, reset notifications, **débloque les
  tenants suspendus** (sémantique alignée avec
  `renewAfterPayment` après IPN Paydunya).
- [x] `SuperAdminController` étendu : POST /tenants (création),
  PATCH /tenants/{slug} (édition limitée à
  name/timezone/country/currency, slug et subdomain figés),
  POST /tenants/{slug}/force-plan (reason 5 chars min
  obligatoire), GET /audit (paginé, filtres
  actor/tenant/action).
- [x] **Audit rétroactif** : `suspendTenant` et
  `reactivateTenant` du Sprint 13 écrivent désormais dans le
  nouvel audit log en plus du logger Monolog existant.

**Backend — Correctifs intercalés**
- [x] `AuthenticationSuccessListener` sur événement Lexik JWT
  `on_authentication_success` — met à jour `lastLoginAt` après
  chaque login réussi. Filtre `instanceof StaffUser` (le
  SuperAdmin a son propre champ dans `public.users`).
  ⚠️ Manque pré-existant depuis Sprint 2, révélé maintenant.
- [x] `AuditService` injecté dans `StaffController` et
  `StaffInvitationService` — toutes les actions sensibles staff
  écrivent désormais dans la table tenant `audit_logs` :
  `staff_user.created`, `.updated`, `.password_reset`,
  `.deactivated`, `.reactivated`, `staff_invitation.created`,
  `.revoked`, `staff_user.created_via_invitation`.
- [x] `AuditLogRepository::findByEntity()` et
  `findByStaffUser()` (filtre par email = champ stable face aux
  désactivations). Tri secondaire `id DESC` (UUID v7) pour
  trancher les égalités sur `created_at` (TIMESTAMP(0)).
- [x] Endpoints `GET /api/staff/{id}/audit` (historique sur ce
  profil, 50 entrées max) et `GET /api/staff/{id}/activity`
  (actions FAITES par cet employé, 100 entrées max).
- [x] **Refactor `TempPasswordGenerator`** — service partagé
  dans `Shared/Security/`. Suppression de 3 implémentations
  privées dupliquées (`StaffController`, `OnboardingService`,
  `CreateSuperAdminCommand`). Plus de dette sur ce point.

**Frontend [13bis-A]**
- [x] Module `staff/` : `MODULE_ACCESS=['MANAGER']`, entrée
  sidebar « Personnel » (`ti-users-group`).
- [x] `services/staff.service.ts` — instance authentifiée +
  instance publique séparée pour les routes
  `/public/invitations`.
- [x] `modules/staff/views/StaffListView.vue` — stats X/Y
  utilisateurs avec warning >=80%, filtres, tableau membres
  actifs/désactivés + invitations.
- [x] `modules/staff/components/InviteStaffModal.vue` +
  `CreateStaffModal.vue` (modal d'affichage password temporaire
  one-shot avec bouton Copier).
- [x] `modules/staff/views/StaffDetailView.vue` — édition,
  reset password (one-shot), désactivation/réactivation avec
  confirmation inline.
- [x] `modules/staff/views/AcceptInvitationView.vue` — page
  publique plein écran, validation password 8 chars +
  confirmation, redirect login après accept.

**Frontend [13bis-B + correctifs]**
- [x] `modules/superadmin/views/CreateTenantView.vue` —
  formulaire 2 sections (hôtel/manager), normalisation slug
  live (NFD + accents + non-alphanum → tirets), radio
  Essai/Actif avec warning visible sur le mode actif, modal
  résultat avec password copiable + redirect.
- [x] `modules/superadmin/views/SuperAdminAuditView.vue` —
  filtres actor/tenant/action avec debounce 300 ms, tableau
  cliquable (expand inline pour payload JSON + UA complet),
  pagination 20/50/100, mapping FR des actions.
- [x] `modules/superadmin/views/TenantDetailView.vue` étendu :
  bouton « Modifier » avec mode édition inline
  (name/timezone/country/currency), hint slug figé, section
  collapsible « Forcer un plan » avec reason 5 chars min.
- [x] `modules/superadmin/views/TenantsListView.vue` — bouton
  « + Nouveau tenant ».
- [x] Nav SuperAdmin : ajout entrée « Audit » + routes
  `/superadmin/tenants/new` et `/superadmin/audit`.
- [x] `modules/staff/views/StaffDetailView.vue` étendu :
  système à deux onglets « Activité » (par défaut) /
  « Historique du compte », mapping FR enrichi (22 actions
  staff / reservation / guest / room / housekeeping / billing),
  dots colorés par catégorie sémantique, chargement parallèle
  au mount via `Promise.all`.

**Tests**
- [x] `tests/Unit/Service/SubscriptionLimitCheckerTest` —
  4 tests (limit reached / enterprise unlimited / below
  limit / no subscription).
- [x] `tests/Functional/Api/Staff/StaffInvitationTest` —
  12 tests (RBAC, conflits, public GET/accept, expiration,
  double-accept, limite plan, audit assertions sur invite /
  accept sans acteur / revoke).
- [x] `tests/Functional/Api/Staff/StaffCrudTest` — 18 tests
  CRUD + audit (`testCreateLogsAudit`,
  `testUpdateLogsAuditWithBeforeAfter`,
  `testUpdateNoChangesSkipsAudit`,
  `testResetPasswordLogsAuditWithoutLeakingSecrets`,
  `testActivityEndpointReturnsActionsDoneByStaff`,
  `testActivityEndpointDoesNotReturnOtherStaffActions`).
- [x] `tests/Functional/Api/Auth/LoginUpdatesLastLoginAtTest`
  — 2 tests (login OK met à jour, login échoué ne touche pas).
- [x] `tests/Functional/SuperAdmin/SuperAdminTest` — passage
  de 8 → 19 tests (création / validation slug + plan + email
  / status active / update / force-plan + reason min / RBAC /
  audit endpoint / régression `testSuspendNowWritesToAudit`).

⚠️ Tous marqués `@group integration` — cohérent avec le pattern
Sprint 13 (point d'attention au backlog).

**Suite globale finale** : 193 tests verts standard
(`make test`) + 103 tests integration (`make test-integration`,
1 échec pré-existant `testCreateReservationViaApiAppliesPromoCode`
hérité Sprint 9, déjà au backlog).

**Ajouts notables (Sprint 13bis)** — décisions et apprentissages
- [x] **Décision « `forcePlan` débloque les tenants suspendus »** :
  si un SuperAdmin force un nouveau plan (geste commercial), le
  service met aussi `tenant.status = ACTIVE` automatiquement.
  Sémantique cohérente avec `renewAfterPayment` (après IPN
  Paydunya réussi). Évite l'étape inutile « réactiver puis
  forcer le plan ».
- [x] **Décision `updateTenant` skip-if-empty** : si aucun champ
  n'est fourni dans le PATCH, 422 « Aucun champ à mettre à jour »
  plutôt que succès vide. Évite de polluer l'audit log avec des
  updates fantômes.
- [x] **Double logging Monolog + audit_log** : les deux actions
  `tenant.suspended` / `reactivated` du Sprint 13 écrivaient
  déjà via Monolog (Papertrail) ; le 13bis ajoute le
  `SuperAdminAuditService` en PLUS. Les deux coexistent —
  Monolog pour debug, audit log pour traçabilité UI /
  conformité. Pattern à reproduire sur toute action sensible.
- [x] **Distinction Activité vs Historique** : sur la fiche
  employé, deux vues complémentaires :
  - Activité = actions FAITES par l'employé (filtre par
    `staffUserEmail`) — utile au quotidien pour suivi d'équipe.
  - Historique = actions SUR le profil (filtre par
    `entityType` + `entityId`) — utile pour conformité RH.
- [x] **Tracking `lastLoginAt`** : manque depuis Sprint 2,
  révélé sur la fiche employé. Corrigé via listener Lexik JWT
  filtré sur `StaffUser`.
- [x] **Refactor `TempPasswordGenerator`** : 3 duplications
  privées factorisées en un service partagé dès qu'un 3e
  consommateur s'est présenté (`OnboardingService::provision`).

**Livrable** : un manager hôtel peut désormais inviter par email
ou créer directement son équipe, gérer leurs rôles, désactiver
(soft), et consulter pour chaque employé son activité dans le
système et l'historique de modifications de son profil. Un
SuperAdmin peut créer manuellement un tenant (sans OTP, mode
trial ou actif), éditer ses paramètres, forcer un plan (geste
commercial avec reason obligatoire), et consulter le journal
d'audit complet avec IP/UA. Le couplage Sprint 12 ↔ Sprint 13 ↔
Sprint 13bis est cohérent et tracé bout-en-bout.

---

### ✅ Sprint 13ter — Configuration hôtel (manager) + templates seed
**Objectif** : combler le trou opérationnel de la création de
tenant — un nouveau tenant ne pouvait pas créer ses chambres,
types ou étages depuis l'UI (les fixtures dev faisaient le job,
montage qui ne tenait pas en prod). Périmètre :
- CRUD complet Floor / RoomType / Room côté manager
- Templates seed côté SuperAdmin pour pré-remplir un tenant à
  sa création (vente directe / migration depuis autre PMS)
- Limites quantitatives `maxRooms` enfin câblées

⚠️ Décision retenue (Variante 2) : manager configure tout
lui-même (priorité absolue) + SuperAdmin pré-remplit via
templates au moment de la création. Pas d'écran SuperAdmin
dédié à la configuration fine d'un tenant (Variante 3 reportée
au backlog).

**Backend — Migration + durcissement**
- [x] Migration tenant `Version20260608000000HardenFloorsAndAuditConfig`
  enregistrée dans `TenantMigrationRegistry`. Ajoute UNIQUE
  index sur `floors.number` (dette pré-existante du Sprint 4 :
  deux étages avec même numéro étaient possibles). Ajoute
  `created_at` / `updated_at` (NOT NULL après backfill UPDATE)
  sur `floors` et `room_types`.

**Backend — Limites quantitatives (suite Sprint 13bis-A)**
- [x] `SubscriptionLimitChecker::assertCanAddRoom()` — décompte
  chambres actives, ENTERPRISE bypass (null = illimité), message
  d'erreur explicite avec nom du plan et limite chiffrée.
  Refactor des méthodes privées `countUserConsumption()` et
  `countActiveRooms()` pour éviter la duplication entre `assert*`
  et `get*Usage()`.
- [x] `getRoomUsage()` retourne `{used, max, plan}` pour stat
  card UI.

**Backend — CRUD Floor**
- [x] Entité `Floor` enrichie avec timestamps + groupes
  serialization `floor:read`.
- [x] `App\Controller\Api\FloorController` route prefix
  `/api/floors`. RBAC : ROLE_MANAGER pour write, ROLE_ACCESS_ROOMS
  pour read. Endpoints : GET liste (sortée par number), POST
  create, PUT update (avec check d'unicité du nouveau number),
  DELETE (FK protect avec message listant les chambres
  bloquantes), POST deactivate, POST reactivate. Audit log
  systématique sur chaque écriture.
- [x] `FloorService` — toute la logique métier déléguée
  (validation, audit, exceptions).
- [x] DTOs `CreateFloorDTO` / `UpdateFloorDTO`.

**Backend — CRUD RoomType**
- [x] Entité `RoomType` enrichie avec timestamps.
- [x] `App\Controller\Api\RoomTypeController` route prefix
  `/api/room-types`. RBAC identique. Endpoints : GET liste
  (sortOrder ASC), POST create (name unique case-insensitive,
  baseRateXof > 0, maxOccupancy ≥ 1), PUT update, DELETE
  (FK protect avec liste des chambres bloquantes, troncature
  `…` si > 10).
- [x] Le PUT legacy `/api/rooms/types/{id}` (Sprint 4) est
  GARDÉ en parallèle pour ne pas casser le front existant.
  À supprimer au Sprint 14 (dette).

**Backend — CRUD Room étendu**
- [x] `RoomController` étendu (le PUT existait déjà depuis
  Sprint 4) : POST create unitaire avec check
  `assertCanAddRoom`, POST bulk (max 50 chambres par batch,
  numérotation séquentielle avec préfixe optionnel,
  **rollback transactionnel** si la limite plan est dépassée
  en cours de bulk), DELETE soft (active=false, FK protect
  via réservations `confirmed`/`checked_in` avec retour des
  confirmation numbers bloquants), POST reactivate (re-check
  limite plan), GET `/api/rooms/usage` pour la jauge X/Y.
- [x] `RoomService::bulkCreateRooms` : pre-flight check
  d'unicité des numéros AVANT la transaction, `flush()` à
  chaque itération pour que le count voie les chambres
  précédemment persistées, `detach()` après rollback pour
  protection cleanup. Audit log unique `room.created_bulk`
  avec payload `{count, range}` (pas N entrées).
- [x] DTOs `CreateRoomDTO`, `BulkCreateRoomsDTO`,
  `UpdateRoomDTO`, `CreateRoomTypeDTO`, `UpdateRoomTypeDTO`.

**Backend — Templates seed (SuperAdmin)**
- [x] `App\Platform\Admin\Domain\Service\TenantSeedService` :
  constantes publiques `TEMPLATE_EMPTY`, `TEMPLATE_SMALL_HOTEL`,
  `TEMPLATE_MEDIUM_HOTEL`, `ALLOWED_TEMPLATES`. Méthode `seed()`
  qui doit être appelée DEPUIS l'intérieur du search_path
  tenant (invariant documenté). `small_hotel` = 1 étage + 1
  type "Standard" 25000 XOF + 5 chambres. `medium_hotel` = 2
  étages + 2 types (Standard 25k / Deluxe 45k) + 12 chambres.
- [x] `OnboardingService::provision()` accepte 3e paramètre
  `$seedTemplate` (default `TEMPLATE_EMPTY`), validation
  contre `ALLOWED_TEMPLATES`, appel `tenantSeedService->seed()`
  À L'INTÉRIEUR du `try` block du `SET search_path` (invariant
  respecté). Le `finally` garantit le retour à `public`.
- [x] `SuperAdminController::createTenant` accepte
  `seed_template` dans le payload, validation et propagation
  à `provision()`. Audit log enrichi avec le template choisi.

**Backend — Commandes de gestion (livrées pendant le debug)**
- [x] `App\Platform\Tenant\Application\Command\CleanupOrphanSchemasCommand`
  (`stayos:tenant:cleanup-orphans`) : liste les schemas
  `hotel_*` orphelins (= sans tenant correspondant dans
  `public.tenants`), modes `--dry-run` et `--dump-to=<dir>`
  (pg_dump avant DROP), confirmation interactive obligatoire,
  double sécurité par recoupement (refus explicite de dropper
  un schema actif même si la liste est corrompue entre temps).
- [x] `App\Platform\Tenant\Application\Command\ProvisionTenantCommand`
  (`stayos:tenant:provision <slug>`) : recrée le schema d'un
  tenant existant si son schema venait à disparaître (outil
  de réparation manuelle, validation préventive du nom de
  schema avec regex).

**Frontend — Module Configuration (manager)**
- [x] `MODULE_ACCESS.configuration = ['MANAGER']`, entrée
  sidebar "Configuration" (ti-settings) après "Personnel".
- [x] `services/room.service.ts` étendu avec `create` / `bulk`
  / `softDelete` / `reactivate` / `update` + nouveaux services
  `floorService` et `roomTypeService` (extraction propre).
- [x] `modules/property/views/HotelConfigurationView.vue` —
  3 onglets (Étages / Types / Chambres) avec inline edit
  Étages, modal édition Types, tableau filtrable Chambres
  avec création unitaire + bulk + edit + soft delete +
  reactivate.
- [x] `modules/property/components/BulkCreateRoomsModal.vue`
  — sélecteurs étage/type, aperçu live des numéros, gestion
  422 limite plan avec message "vous pouvez ajouter N
  supplémentaires".
- [x] Stat card "X/Y chambres — Plan {nom}" dans l'onglet
  Chambres.

**Frontend — Templates SuperAdmin**
- [x] `CreateTenantView.vue` étendu — select template avec
  3 options et mention "modifiable par le manager après
  création". Default `empty`. Modal de résultat mentionne le
  template appliqué.

**Tests**
- [x] `tests/Unit/Service/SubscriptionLimitCheckerRoomTest`
  — 4 tests miroirs de `assertCanAddUser` (no sub, enterprise,
  limit reached, below limit).
- [x] `tests/Functional/Api/Property/FloorControllerTest`
  — CRUD RBAC, conflits unicité, DELETE protect avec chambres
  liées.
- [x] `tests/Functional/Api/Room/RoomTypeControllerTest`
  — CRUD RBAC, unicité case-insensitive, DELETE protect.
- [x] `tests/Functional/Api/Room/RoomCrudTest` — création
  unitaire, bulk avec rollback transactionnel à la limite,
  soft delete, reactivate avec limite, get usage.
- [x] `tests/Functional/SuperAdmin/CreateTenantWithTemplateTest`
  — empty / small_hotel / medium_hotel / invalid template.

⚠️ Tous marqués `@group integration` — cohérent avec le
pattern Sprint 13 / 13bis.

**Hotfixes (correctifs intercalés)**
- [x] **Hotfix #1** : `notif.toast()` n'existait pas dans le
  store Pinia ; tous les appels remplacés par
  `notif.pushUiToast(severity, title)`. Le `severity` enum
  est `{info, success, warning, alert}` — il n'y a PAS de
  `'error'`, c'est `'alert'` qu'il faut. ~26 occurrences dans
  `HotelConfigurationView` + `BulkCreateRoomsModal`.
- [x] **Hotfix #2** : `StaffListView.vue` — bouton "Désactiver"
  désormais masqué pour le manager loggué sur sa propre ligne,
  remplacé par un marqueur italique "Vous". `StaffDetailView`
  gérait DÉJÀ correctement le cas via `isSelf()` mais la
  liste avait été oubliée (asymétrie liste/détail).
- [x] **Hotfix #3** : régression `roomService.getTypes is not
  a function` — le refactor 13ter avait extrait la gestion des
  types dans `roomTypeService` mais supprimé `roomService.getTypes()`
  sans mettre à jour les consommateurs. 3 vues touchées
  (`RatesView`, `RatePlanForm`, `RoomDetailView`). Le
  `Promise.all` dans `RatesView.fetchAll()` rejetait
  globalement et le `catch {}` muet avalait l'erreur
  silencieusement → page Tarifs cassée sur TOUS les tenants.
  Correctif inclut `Promise.allSettled` pour tolérance
  partielle + logs explicites par requête échouée.

**Debug schemas orphelins**
- [x] Diagnostic : 24 schemas `hotel_*` en BDD pour 4 tenants
  actifs dans `public.tenants`. Cause : `CREATE SCHEMA` non-
  transactionnel en PostgreSQL → les rollbacks de tests
  fonctionnels `@group integration` ne droppent pas le schema.
  Idem `make fixtures` avec purger qui DELETE/TRUNCATE sans
  DROP SCHEMA explicite.
- [x] Résolution : commande `cleanup-orphans` livrée +
  commande `tenant:provision` en bonus. Nettoyage manuel
  exécuté avec 20 dumps SQL préalables dans
  `/tmp/stayos-orphans/` (768 KB) : 4 tenants finaux (savana,
  villa-collines, balladin, test-new-tenant), 4 schemas,
  0 orphelin. Idempotence vérifiée (re-run `--dry-run`).

**Ajouts notables (Sprint 13ter) — décisions et apprentissages**
- [x] **Décision « rollback transactionnel sur bulk create »** :
  si la limite plan est dépassée au milieu d'un mass-create
  de 20 chambres, **toute la transaction est annulée**, pas
  de création partielle. Sémantique atomique attendue par
  l'utilisateur.
- [x] **Décision « slug en lecture seule dans l'édition tenant »**
  (du Sprint 13bis-B, confirmée Sprint 13ter) : changer un
  slug casse les liens, IPN Paydunya, sessions JWT. Pas en V1.
- [x] **Décision « Variante 2 » pour la configuration** :
  manager configure tout lui-même + SuperAdmin pré-remplit
  via templates. Pas d'écran SuperAdmin dédié à la configuration
  fine (Variante 3 backlog).
- [x] **Décision « templates = amorces modifiables »** : ce
  qui est créé par `small_hotel` / `medium_hotel` reste
  modifiable par le manager. Le SuperAdmin ne fige rien.

**Livrable** : un manager hôtel peut désormais configurer son
inventaire complet (étages, types, chambres) sans intervention
SuperAdmin. Le SuperAdmin peut pré-remplir un tenant avec un
template pour livrer un hôtel clé-en-main à un client en vente
directe. Les limites plan (maxUsers du Sprint 13bis + maxRooms
de ce sprint) sont enforced de bout en bout. L'environnement
de dev dispose désormais d'outils de nettoyage et de
réparation des schemas tenant.

---

### ✅ Sprint 13quater — Night audit (clôture comptable journalière)
**Objectif** : combler un manque produit fondamental — StayOS
n'avait aucune notion de clôture de journée (standard de
l'industrie hôtelière Opera, Mews, Cloudbeds). Périmètre :
- Workflow de clôture avec verrouillage comptable
- Snapshot JSON immutable des chiffres figés
- Génération PDF de la liasse standard
- UI réceptionniste dédiée

⚠️ Décision validée : business date avec heure de bascule
configurable par tenant (défaut 5h matin), séquentialité
obligatoire, verrou métier qui refuse toute modif d'opération
en journée close (corrections via opérations datées du jour
courant), MANAGER seul à pouvoir rouvrir avec raison ≥ 5 chars
obligatoire.

**Backend — Fondations [13quater-A]**
- [x] Migration tenant `Version20260610000000CreateDailyCloses`
  enregistrée dans `TenantMigrationRegistry`. Table avec UNIQUE
  INDEX sur `business_date`, INDEX DESC sur `closed_at`.
  Snapshot persisté en `jsonb`. Reopen tracé via colonnes
  séparées (`reopened_at`, `reopened_by_email`, `reopen_reason`).
- [x] `Tenant::getBusinessDayCutoffHour()` / `setBusinessDayCutoffHour()`
  — stockage dans `settings` JSON nullable, validation 0-23,
  défaut 5h. Pas de migration nécessaire (utilise le JSON
  existant).
- [x] Entité `DailyClose` + `DailyCloseRepository` —
  `findByBusinessDate`, `findLatest`, `findLatestEffective`
  (WHERE `reopened_at IS NULL`), `paginate`, `countAll`.
- [x] `BusinessDateService` — calcule la business date courante
  selon `cutoffHour` configuré, refactor `resolve()` privée
  partagée entre `getCurrentBusinessDate()` et
  `toBusinessDate($instant)`. Reconstruction explicite à minuit
  dans la TZ tenant pour gérer DST.
- [x] `DailyCloseLockChecker::assertCanModifyDate()` — message
  FR explicite, bypass si pas de close, bypass si dernière
  close rouverte.
- [x] `DailyCloseService` — `getCurrent` (status + canClose +
  séquentialité), `close($actor, $force=false)` (validation
  séquentielle + snapshot + persistance + audit + logger),
  `reopen($close, $actor, $reason)` avec sécurité "seule la
  dernière effective peut être rouverte" via UUID `equals`.
- [x] `KpiService::dashboardForDate($date)` — extension pour
  permettre le calcul historique ; `dashboardToday()` devient
  wrapper.
- [x] Câblage du verrou métier dans `ReservationEngine` :
  `create` (date checkIn, autorise futur), `update` (double
  check ancienne + nouvelle date), `cancel`, `checkIn`,
  `checkOut`.
- [x] Câblage du verrou dans `InvoiceService::issue` et
  `recordPayment`. Commentaire explicite : un paiement
  d'aujourd'hui sur facture passée close est autorisé car
  c'est le geste corrigeant du jour.
- [x] `NightAuditController` — `GET /current`, `POST /close`,
  `GET /` (paginé), `GET /{id}`, `POST /{id}/reopen` (MANAGER
  strict via override `IsGranted`).

**Backend — Checklist + PDF [13quater-B]**
- [x] `NightAuditChecklistService` — 4 warnings détectés :
  `arrivals.pending` (CONFIRMED arrivant aujourd'hui non
  checked-in), `departures.pending` (CHECKED_IN partant
  aujourd'hui non checked-out), `invoices.draft` (drafts
  pour résas checked-out aujourd'hui), `rooms.orphan_occupied`
  (rooms OCCUPIED sans résa active). Helper `truncate()` avec
  constante `DETAILS_MAX=10`.
- [x] `DailyCloseService::close()` étendu avec flag `$force`
  (défaut `false`). Si warnings et `!$force` → 422. Si forcé,
  warnings persistés dans `snapshot['warnings']` pour
  traçabilité long-terme. Audit log enrichi avec
  `warningsCount` + `forced`.
- [x] `NightAuditPdfService::generate(DailyClose)` — pattern
  Dompdf identique à `InvoiceService::generatePdf`. PDF
  régénéré à chaque appel à partir du snapshot immutable
  (invariant documenté).
- [x] Template Twig `night_audit/daily_close_pdf.html.twig` —
  pas de `@page` CSS (respect du backlog Dompdf html5parser),
  wrapper `.page` avec padding pour marges. Sections :
  en-tête + bannières "RÉOUVERT" / "FORCÉE", KPIs, activité
  du jour, caisse par méthode, factures avec TVA approximative,
  état chambres (tronqué à 50 + "et X autres"), warnings
  détaillés, footer. Lecture défensive du snapshot via
  `|default(...)` partout.
- [x] Endpoints ajoutés : `GET /checklist`, `GET /{id}/pdf`
  (Content-Type pdf, attachment, `Cache-Control: no-store`).
- [x] Tests étendus : `testCloseRefusedWhenWarningsAndNotForced`,
  `testCloseWithForceAcceptsWarnings`,
  `testChecklistEndpointReturnsWarnings`,
  `testPdfEndpointReturnsBinary` (vérification magic bytes
  `%PDF-` + taille > 1000), et surtout
  `testReopenedCloseDoesNotEnforceLock` (E2E : close → tenter
  modif refusée → reopen → modif acceptée).
- [x] Tests `LockedDayPreventsModificationTest` complétés :
  `testCannotUpdatePastReservationOnClosedDay`,
  `testCannotCancelPastReservationOnClosedDay`,
  `testCheckInDoesNotFireLockWhenTodayIsNotClosed` (vérification
  d'absence de faux positif via
  `assertStringNotContainsString('clôturée')`),
  `testCanRecordPaymentTodayWhenTodayIsNotClosed`.

**Frontend [13quater-C]**
- [x] `MODULE_ACCESS.night_audit = ['RECEPTIONIST', 'MANAGER']`,
  entrée sidebar "Clôture journalière" avec icône
  `ti-moon-stars`.
- [x] Types `night-audit.ts` (NightAuditCurrent, Warning,
  Checklist, DailyClose, Snapshot, DailyCloseListResponse).
- [x] Service `night-audit.service.ts` — méthodes get/list/
  close/reopen + `downloadPdf` avec `responseType: 'blob'` et
  `URL.revokeObjectURL` dans `finally`.
- [x] `NightAuditView.vue` — chargement parallèle (current +
  checklist + history) via `Promise.all`, 3 états visuels
  (déjà clôturée / bloquée / active), historique paginé avec
  badges "Effective" / "Rouverte", boutons download PDF +
  voir détail. `pushUiToast('alert'/'success')` partout.
- [x] `NightAuditDetailView.vue` — bannières "RÉOUVERTE" et
  "FORCÉE", bouton "Réouvrir" masqué côté UI si
  `auth.userRole !== 'MANAGER'` + garde-fou serveur 403
  converti en toast.
- [x] `SnapshotDisplay.vue` — 6 cards (KPIs, activité, caisse,
  factures, chambres, warnings). Lecture défensive `??`
  partout. `fmtXof` avec `Intl.NumberFormat('fr-FR')` (espaces
  fines). Grid responsive 4 → 2 cols mobile. Badges par
  status cohérents avec PDF.
- [x] `WarningList.vue` — cards dépliables (mode `dense` qui
  force ouvert + masque chevron), `formatDetail` switch par
  code de warning avec fallback `JSON.stringify`.
- [x] `ConfirmCloseModal.vue` — message adapté selon présence
  de warnings, récap warnings en `dense=true` si présents,
  bouton "Confirmer la clôture" ou "Confirmer la clôture
  forcée".
- [x] `ReopenModal.vue` — validation client `trim() >= 5`
  chars cohérente avec serveur, char counter live, reset
  après close pour éviter persistance entre ouvertures.

**Ajouts notables (Sprint 13quater) — décisions et apprentissages**
- [x] **Décision « business date avec cutoff configurable »** :
  défaut 5h matin, paramétrable par tenant via
  `settings['business_day_cutoff_hour']`. Un check-out à 02h
  reste comptabilisé sur la veille. Gestion DST via
  reconstruction explicite à minuit dans la TZ tenant.
- [x] **Décision « snapshot immutable »** : tous les chiffres
  d'une close sont figés en JSON au moment de la clôture. Le
  PDF est régénéré à chaque appel mais à partir du snapshot,
  jamais recalculé. Garantit la cohérence du document même
  après reopen/reclose.
- [x] **Décision « séquentialité obligatoire »** : pas de
  clôture J si J-1 pas clos. Sinon trous comptables. Si oubli
  de plusieurs jours, l'administrateur doit reprendre dans
  l'ordre.
- [x] **Décision « warnings non bloquants + force flag »** :
  les 4 warnings (arrivals.pending, departures.pending,
  invoices.draft, rooms.orphan_occupied) bloquent la close
  par défaut mais le réceptionniste peut forcer via
  `force: true` après confirmation UI. Les warnings forcés
  sont persistés dans le snapshot pour traçabilité.
- [x] **Décision « reopen MANAGER strict + reason ≥ 5 chars »** :
  seule la dernière close effective peut être rouverte (sinon
  trous séquence). MANAGER strict, RECEPTIONIST refusé en 403.
- [x] **Décision « PDF généré à la demande »** : pas de
  stockage, régénération depuis le snapshot immutable.
  `Cache-Control: no-store` pour ne pas cacher côté navigateur.

**Livrable** : un PMS qui supporte le workflow standard de
clôture comptable journalière. Le réceptionniste peut clôturer
sa journée en fin de service, télécharger la liasse PDF, et la
journée close est verrouillée contre toute modification
rétroactive. Si une correction s'avère nécessaire, le manager
peut rouvrir la close avec raison tracée, et toute modification
reste visible dans le snapshot et l'audit log.

⚠️ **Manques métier critiques découverts** (ouvrent le Sprint
13quinquies) :
- **No-show** : l'enum existe mais aucune action service/API/UI.
  Le warning `arrivals.pending` du night audit recommande
  "Marquez no-show" sans qu'on puisse le faire.
- **Refund / avoir** : `PaymentStatus` n'a pas de `REFUNDED`,
  aucune sortie de caisse négative ni de credit note.

---

### ⬜ Sprint 13quinquies — Corrections financières
**Objectif** : combler les manques métier identifiés à la
clôture du 13quater. Un PMS ne peut pas aller en prod sans
ces mécanismes. Périmètre prévisionnel (à arbitrer) :
- **No-show** : action `markNoShow` côté manager/réceptionniste,
  facturation configurable (rien / 1ère nuit / total),
  intégration au warning `arrivals.pending` du night audit.
- **Refund / remboursement** : statut `REFUNDED` sur Payment,
  service de remboursement avec audit obligatoire, sortie de
  caisse négative tracée.
- **Politique d'annulation** : frais d'annulation selon
  préavis (gratuit > 48 h, 1 nuit retenue 24-48 h, total < 24 h
  par défaut, configurable par tenant). À arbitrer : inclure
  ou reporter en V2 ?

Le prompt principal arbitrera : (a) politique no-show par
tenant ou cas par cas, (b) refund minimaliste (paiement
négatif tracé) ou complet (credit note), (c) frais
d'annulation inclus ou reportés.

**Livrable** : un PMS qui sait traiter les écarts financiers
courants — clients absents (no-show), remboursements partiels
ou totaux, et annulations avec frais conformes à une politique
explicite. Bloquant avant le passage en prod.

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
| SaaS (suite) | S13ter | 1 semaine | Configuration hôtel manager + templates seed |
| SaaS (suite) | S13quater | 1 semaine | Night audit / clôture comptable journalière |
| SaaS (suite) | S13quinquies | ~1 semaine | Corrections financières (no-show, refund, annulation) |
| Production | S14 | 1 semaine | Déploiement + sécurité finale |
| **Total** | **17 sprints** | **~17 semaines** | **App production-ready** |

---

## Évolutions futures (backlog hors-sprint)

Idées et besoins identifiés en cours de développement, à planifier
après les 16 sprints initiaux ou à intégrer dans un sprint dédié.

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
- ✅ **Module de gestion du personnel (vraie RH)** — livré au
  **Sprint 13bis** : invitations email avec token signé,
  CRUD complet, soft delete (préserve audit log), RBAC fin,
  password reset one-shot, journal d'activité par employé +
  historique du compte (2 onglets sur la fiche).
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
- ✅ **Limites quantitatives des plans** — livré complètement :
  - `maxUsers` : Sprint 13bis-A (`assertCanAddUser`, count
    `StaffUser` actifs + `StaffInvitation` PENDING).
  - `maxRooms` : Sprint 13ter (`assertCanAddRoom`, bulk avec
    rollback transactionnel si dépassement en cours de batch).
  - Affichage UI : stat cards « X/Y utilisateurs » et
    « X/Y chambres — Plan {nom} » dans les onglets respectifs.
    Aperçu live « N supplémentaires possibles » dans la modal
    bulk create. Bypass ENTERPRISE (limite `null`) sur les
    deux limites.
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

### Audit & traçabilité
- **Normaliser `entityType` dans `audit_logs`** (priorité moyenne) :
  incohérence identifiée Sprint 13bis-A. `Reservation` utilise
  `'Reservation'` (PascalCase), staff utilise `'staff_user'`
  (snake_case), les autres modules sont probablement encore sur
  d'autres conventions. Décider d'une seule convention
  (snake_case recommandé) et migrer les anciens logs +
  unifier dans les services. Le journal d'activité par employé
  s'en moque (il filtre par `staffUserEmail`), mais la lecture
  d'un historique par entité (`findByEntity`) deviendrait
  ambigüe si quelqu'un cherchait du snake_case alors qu'on a
  stocké du PascalCase.
- **Bug `entityId='new'` dans `ReservationEngine::create`**
  (priorité moyenne) : l'audit log est écrit AVANT le flush
  Doctrine, donc `entityId` vaut toujours la chaîne `'new'` au
  lieu de l'ID réel. Conséquences : (1) le lien depuis le
  journal d'activité vers la réservation est impossible ; (2)
  `findByEntity('Reservation', 'new')` retourne TOUS les
  `reservation.created` du tenant. À corriger en remontant le
  `auditService->log()` APRÈS le flush dans le repository.
- **Filtre date dans `/superadmin/audit`** (priorité basse) :
  pas de `?from` / `?to` en V1. Avec un volume croissant
  (toutes les actions sensibles SuperAdmin + suspend/reactivate
  rétroactifs), la navigation par pagination seule deviendra
  pénible. À ajouter quand le volume le justifie.
- **Filtre `?plan=` dans `/superadmin/tenants`** (priorité basse) :
  la sous-requête actuelle matche TOUTE subscription historique
  ayant été sur ce plan, sans filtrer sur `status='active'`.
  Pas un problème V1 (1 subscription par tenant), à durcir en
  V2 quand on aura des historiques de upgrade/downgrade.
- **Symétrie des protections UI liste/détail** (priorité basse) :
  leçon Sprint 13ter — `StaffListView` exposait le bouton
  « Désactiver » sur soi-même alors que `StaffDetailView` le
  masquait correctement via `isSelf()`. Pattern à vérifier
  ailleurs : partout où une logique de protection s'applique
  à un élément (RBAC, self-edit, soft-delete...), vérifier
  les DEUX vues (liste + détail) où il peut apparaître. Idéal :
  un helper `canMutate(item)` partagé entre liste et détail
  pour ne jamais désynchroniser.
- **TVA calculée en float dans le template PDF night audit**
  (priorité basse, Sprint 13quater-B) : le template Twig fait
  `totalTtc / 1.18 * 0.18` en float alors que tout le backend
  utilise bcmath. Imprécision possible à 1-2 centimes près
  sur de gros volumes. Fix : stocker `vatXof` directement
  dans `snapshot.invoices` via bcmath au moment de la close,
  pour que le PDF et l'UI lisent une valeur précalculée et
  cohérente avec la facturation.
- **Cutoff hardcodé "5 h" dans NightAuditView**
  (priorité moyenne, Sprint 13quater-C) : la sub-card
  "Cutoff configuré : 5 h" est codée en dur côté frontend
  alors que la valeur est configurable par tenant dans
  `Tenant.settings['business_day_cutoff_hour']`. Si un tenant
  reconfigure son cutoff, l'UI affichera toujours 5 h.
  Fix : exposer la valeur réelle dans le payload
  `GET /api/night-audit/current` (ou un endpoint
  `/tenant/settings`) et l'afficher dynamiquement côté UI.
- **`lastEffectiveClose` dépendant de la pagination**
  (priorité basse, Sprint 13quater-C) : `NightAuditView` lit
  `history.data[0]` pour pointer vers la close courante depuis
  le statut "déjà clôturée". Si l'utilisateur a paginé sur
  page 2+, le bouton "Voir le détail" pointerait vers une
  close plus ancienne, pas celle d'aujourd'hui. Fix : exposer
  `lastCloseId` dans le payload `GET /current` côté backend,
  ou charger explicitement la `findLatestEffective()` côté
  frontend indépendamment de la pagination de l'historique.

### Mécanismes métier manquants
Manques fonctionnels structurels identifiés en cours de
livraison — souvent des concepts existants (enums, statuts)
mais sans logique de service ni d'UI. Justifient des sprints
dédiés plutôt qu'un fix ponctuel.

- 🔴 **No-show non implémenté** (priorité HAUTE — Sprint 13quinquies) :
  enum `ReservationStatus::NO_SHOW` existe depuis le Sprint 5
  mais aucune méthode service, aucun endpoint, aucun bouton
  UI. Pire : le warning `arrivals.pending` du night audit
  livré au 13quater-B affiche explicitement "Marquez no-show
  si le client n'est pas venu" alors qu'aucun moyen n'existe
  pour le faire. À traiter au S13quinquies avec arbitrage
  de la politique de facturation associée (rien / 1ère nuit /
  total) et intégration au warning du night audit.
- 🔴 **Refund non implémenté** (priorité HAUTE — Sprint 13quinquies) :
  `PaymentStatus` n'a pas de cas `REFUNDED`. Aucune logique
  de remboursement, d'avoir (credit note), ou de sortie de
  caisse négative. Indispensable pour la prod — tout PMS
  doit savoir tracer un remboursement client (annulation
  tardive, geste commercial, double facturation). À traiter
  au S13quinquies avec arbitrage minimaliste (paiement
  négatif tracé) vs complet (credit note dédiée).
- ⬜ **Politique d'annulation tardive** (priorité moyenne —
  S13quinquies ou V2) : aujourd'hui `ReservationEngine::cancel`
  annule sans contrainte ni frais. Une vraie politique
  implique des frais d'annulation selon le préavis (gratuit
  > 48 h, 1 nuit retenue 24-48 h, total < 24 h par défaut,
  configurable par tenant). À arbitrer dans le S13quinquies :
  inclure dès maintenant (cohérent avec le refund) ou
  reporter en V2.

### Plateforme & onboarding
- **Transactionnaliser `OnboardingService::register/provision`**
  (priorité moyenne, atténuée Sprint 13ter) : si
  `TenantProvisioner::provision()` échoue après que le `Tenant`
  a été persisté, on a un tenant orphelin en BDD sans schema
  PostgreSQL associé (ou l'inverse : schema orphelin sans
  tenant). Dette héritée du Sprint 2 (`register`) et
  reproduite au Sprint 13bis-B (`provision`). Sprint 13ter
  livre un filet de sécurité avec
  `stayos:tenant:cleanup-orphans` pour nettoyer a posteriori,
  mais ce n'est pas la prévention. À traiter au Sprint 14
  pour empêcher la pollution en amont : `beginTransaction()`
  autour de toute la méthode + `DROP SCHEMA IF EXISTS` en cas
  de rollback (`CREATE SCHEMA` est DDL non-transactionnel en
  PostgreSQL — attention au rollback partiel).
- **Variante 3 — UI SuperAdmin de configuration d'un tenant
  existant** (priorité basse, V2) : aujourd'hui le SuperAdmin
  pré-remplit via templates au moment de la création
  (Variante 2, livré Sprint 13ter). Si un commercial doit
  ajuster la configuration d'un tenant DÉJÀ existant, il doit
  se connecter avec les credentials du manager. Pour aller
  plus loin, un écran SuperAdmin dédié permettrait de
  configurer n'importe quel tenant cible sans
  impersonification. Implique : multi-tenant côté lecture pour
  le SuperAdmin (assez complexe), RBAC adapté, audit log
  enrichi sur qui-a-modifié-quel-tenant. Reporté à V2.
- **`TenantUrlBuilder` factorisation incomplète** (priorité
  basse) : helper partagé `Shared/Url/TenantUrlBuilder` créé au
  Sprint 13bis-A pour `EmailService::sendStaffInvitation`, mais
  `SaasInvoiceService::buildTenantUrl` n'a pas encore été
  migré dessus. À finaliser quand on touchera au checkout
  Paydunya au Sprint 14.
- **Cleanup automatique des schemas orphelins dans les tests**
  (priorité moyenne) : la commande
  `stayos:tenant:cleanup-orphans` (livrée hotfix Sprint 13ter)
  est une solution curative. Pollution constatée en juin 2026 :
  20 schemas `hotel_*` orphelins en BDD locale après ~3 mois
  de tests `@group integration`. Origine : `CREATE SCHEMA` est
  un DDL non-transactionnel — le rollback transactionnel d'un
  test ne supprime pas le schema créé. À traiter :
  (1) appeler `TenantProvisioner::deprovision($tenant)` en
  `tearDown` de chaque test qui provisionne ; (2) ajouter un
  hook PHPUnit globall qui purge en fin de suite ; (3) lancer
  `stayos:tenant:cleanup-orphans --dry-run` en pre-commit CI
  pour alerter si des orphelins traînent. Cas particulier :
  les schemas dumpés avant DROP (mode `--dump-to`) restent
  récupérables via `psql -f /tmp/orphan_*.sql`.

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
- **Tests `@group integration` silencieusement skippés**
  (priorité haute, à traiter Sprint 14) : 103 tests
  integration désormais (8 SuperAdmin Sprint 13 +
  12 StaffInvitation + 18 StaffCrud + 4 unit
  SubscriptionLimitChecker + 2 LoginUpdatesLastLoginAt
  Sprint 13bis-A + 11 SuperAdminTest Sprint 13bis-B + autres),
  tous exclus de `make test` standard. `make test` retourne
  « 193 tests verts » sans les exécuter, masquant des
  régressions potentielles. Trois options à arbitrer :
  (A) retirer l'annotation `@group integration` (les tests
  tournent en ~30 s cumulés, acceptable en CI) ;
  (B) garder l'annotation et systématiser un appel à
  `make test-integration` (cible Makefile existante) dans
  un `make test-all` + en CI ;
  (C) revoir tous les tests `@group integration` du projet
  pour décider lesquels doivent vraiment être exclus par
  défaut (vrais tests E2E qui montent une chaîne externe
  vs tests fonctionnels qui ont juste besoin des fixtures).
  À traiter au Sprint 14 (production-ready) en même temps
  que la mise en place de la CI.
- **Tests de schema cleanup en tearDown** (priorité moyenne) :
  les tests fonctionnels `@group integration` qui créent des
  tenants via `OnboardingService::provision` laissent des
  schemas orphelins en BDD (cause directe des 20 orphelins
  trouvés au debug Sprint 13ter). À traiter : ajouter un
  `tearDownAfterClass` qui appelle `cleanup-orphans` ou un
  helper `TenantTestCase::dropProvisionedSchemas()` qui track
  les schemas créés par les tests et les drop à la fin.
  Idéalement complété par un hook CI qui exécute
  `stayos:tenant:cleanup-orphans --dry-run` après la suite
  et fail si le dry-run n'est pas vide.
- **Mode strict TypeScript pour les imports de services**
  (priorité haute, avant prod) : leçon Sprint 13ter — le
  refactor `roomService.getTypes` → `roomTypeService.getAll`
  a cassé 3 vues parce que les imports n'ont pas été détectés
  par le compilateur (`vite build` ne fait pas de type-check
  strict). Vérifier la config `tsconfig.json` :
  `noUnusedLocals`, `noImplicitAny`, `strict: true`. Ajouter
  un step `tsc --noEmit` dans la CI — c'est ce qui aurait
  attrapé la régression au build.
- **Tests E2E sur cancel/checkIn/checkOut avec verrou actif**
  (priorité basse, Sprint 13quater-B) :
  `LockedDayPreventsModificationTest` couvre désormais
  `create`, `update` et `cancel` quand la date concernée
  appartient à une journée close. Les chemins `checkIn` et
  `checkOut` sont testés indirectement par
  `testCheckInDoesNotFireLockWhenTodayIsNotClosed` (vérifie
  l'absence de faux positif via
  `assertStringNotContainsString('clôturée')`). Un test
  direct du déclenchement du verrou sur `checkIn` quand la
  business date courante est hypothétiquement close (cas
  dégénéré qui ne devrait jamais arriver vu la séquentialité)
  manque. Acceptable en V1.

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
- **Audit logging à 2 niveaux (Monolog + table BDD)** : le
  projet utilise deux mécanismes complémentaires —
  `$logger->info()` (channel `business` → Papertrail, debug et
  observabilité externe) et `$auditService->log()` (table
  `audit_logs` tenant ou `superadmin_audit_log` public →
  conformité, traçabilité UI). Ils ne se remplacent PAS. Le
  Sprint 13bis a appris (à la dure) qu'il faut écrire dans les
  deux pour les actions sensibles (suspend, reset password,
  force-plan, désactivation staff), sinon on perd un usage
  (debug ↔ UI). `tenant.suspended` du Sprint 13 n'écrivait QUE
  dans Monolog → invisible dans le journal d'audit UI ; corrigé
  au 13bis-B (test de régression
  `testSuspendNowWritesToAudit`). Réflexe à appliquer : sur
  toute action sensible, prévoir les deux dès l'écriture.
- **Distinction « actions sur l'entité » vs « actions par
  l'acteur »** : les audit logs supportent deux types de
  requêtes — historique d'une entité (`WHERE entityType +
  entityId`) et journal d'un acteur (`WHERE staffUserEmail`).
  Ce sont deux questions différentes et **deux vues UI
  complémentaires**, pas redondantes. Sur la fiche employé du
  Sprint 13bis, l'onglet « Activité » répond à « qu'est-ce
  qu'il a fait ? » et l'onglet « Historique » répond à « qui a
  touché à son profil ? ». Pattern à reproduire ailleurs
  (ex : fiche client → activité commerciale + historique de
  modifications du contact ; fiche chambre → historique des
  réservations + historique des changements de statut).
- **Vérifier l'API réelle des stores Pinia avant tout nouvel
  appel** (Sprint 13ter, hotfix #1) : Claude Code a inventé
  `notif.toast({type, message})` qui n'existait pas dans le
  store. L'API réelle est `pushUiToast(severity, title, body?)`
  avec severity ∈ `{info, success, warning, alert}` (il n'y a
  PAS de `'error'`). Quand on introduit beaucoup d'appels à
  un store dans une vue, **ouvrir le store et lire les
  méthodes exposées** avant d'écrire le premier appel — pas
  d'inférence par analogie avec d'autres frameworks. Idéalement,
  le typage TS strict du store devrait empêcher ce genre
  d'erreur (cf. backlog « mode strict TS imports »).
- **`CREATE SCHEMA` PostgreSQL est non-transactionnel** (Sprint
  13ter, debug) : un rollback de transaction n'annule pas un
  `CREATE SCHEMA`. Toute logique qui crée un schema sans
  `DROP SCHEMA IF EXISTS` explicite en cas d'échec PEUT laisser
  des résidus. Conséquence : les tests fonctionnels qui créent
  des tenants ont besoin d'un teardown explicite, et la BDD de
  dev a besoin d'une commande de nettoyage périodique. La
  commande `stayos:tenant:cleanup-orphans` livrée au 13ter est
  le filet de sécurité curatif, mais ce n'est pas la
  prévention. Réflexe à appliquer : tout endroit qui fait du
  DDL (CREATE/DROP TABLE, INDEX, SCHEMA) doit être encadré par
  du code défensif explicite — pas confier au rollback ORM.
- **Symétrie des protections UI liste/détail** (Sprint 13ter,
  hotfix #2) : quand une logique de protection s'applique à
  un élément (RBAC, self-edit, soft-delete, etc.), elle doit
  être vérifiée systématiquement dans les DEUX vues où
  l'élément peut apparaître (liste vs détail). Pattern à
  reproduire partout — fiche client, fiche réservation, fiche
  facture. Si l'élément n'est PAS éligible à une action dans
  la vue détail, il ne doit PAS l'être non plus dans la vue
  liste. Idéal : un helper `canMutate(item)` partagé entre
  liste et détail pour ne jamais désynchroniser.
- **Snapshot JSON immutable vs recalcul** (Sprint 13quater) :
  pour les données comptables / audit (clôture journalière,
  facture émise, audit log, contrat employé), on FIGE les
  chiffres dans un JSON au moment de l'écriture, et on les
  LIT sans recalculer. Ça garantit la cohérence du document
  même si les données sources changent après (mise à jour de
  tarif, modification d'une chambre, suppression d'un staff).
  Le PDF du night audit est régénéré à chaque appel MAIS à
  partir du `snapshot` immutable, jamais de la BDD courante.
  Le `Cache-Control: no-store` côté réponse HTTP empêche le
  cache navigateur de masquer une donnée corrigée. Pattern à
  reproduire : pour les factures (déjà fait au S7 via
  `Invoice.lines` figées), les bulletins de paie staff, les
  attestations, et toute donnée signée / validée à un instant
  T qui doit rester reproductible.
- **Business date séparée de la date civile** (Sprint 13quater) :
  l'hôtellerie a une notion de "journée d'exploitation" qui
  ne correspond pas exactement à minuit civil. Un check-out
  à 02h du mat appartient comptablement à la veille (le
  client a dormi cette nuit-là). La configurabilité du cutoff
  par tenant (`settings['business_day_cutoff_hour']`, défaut
  5h) permet d'adapter selon le profil hôtelier — un palace
  parisien avec restaurant tardif n'a pas le même cutoff
  qu'un motel africain. Pattern à généraliser : si une
  industrie a une convention temporelle métier qui ne suit
  pas le calendrier civil (exercice fiscal qui ne commence
  pas au 1er janvier, semaine commerciale qui démarre le
  lundi, période de paie qui finit le 25, etc.), il faut
  séparer dès la conception via un service
  `BusinessXxxService` qui fait le pont (`BusinessDateService`,
  `BusinessMonthService`...) plutôt que d'appliquer un offset
  ad hoc à chaque appel. Toute la TZ tenant doit aussi être
  reconstruite explicitement à la frontière (`new
  DateTimeImmutable('Y-m-d 00:00:00', $tenantTz)`) pour gérer
  DST sans surprise.
- **Cohérence backend/frontend des calculs de précision**
  (Sprint 13quater, remarque mineure) : si le backend utilise
  bcmath pour la précision XOF (DECIMAL(10,2) en BDD), les
  rendus côté frontend (UI ou template PDF généré
  server-side) doivent utiliser la même précision. Sinon
  imprécisions à 1-2 centimes près qui peuvent surprendre
  un comptable. Exemple du PDF night audit : la TVA est
  calculée en Twig float (`totalTtc / 1.18 * 0.18`) alors
  que tout le backend est en bcmath. Pattern à généraliser :
  **précalculer les valeurs dérivées dans les snapshots /
  réponses API** plutôt que de laisser le frontend ou un
  template les recalculer. Le client ne devrait jamais
  reproduire une logique métier comptable.
