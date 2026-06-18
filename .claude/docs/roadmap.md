# StayOS — Plan de développement

## Workflow
Claude Code génère le code → l'utilisateur valide → Claude (chat) relit et vérifie la cohérence.
Pour chaque sprint : demander le prompt Claude Code dans le chat, puis soumettre le code généré pour relecture.

## Statut global
- Sprint courant : **Sprint 14-C — Déploiement**
- Dernière mise à jour : 18 juin 2026
- Sprints terminés : 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 13bis, 13ter, 13quater, 13quinquies, 14-A.1, 14-A.2, 14-A.3, 14-B

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

### ✅ Sprint 13quinquies — Corrections financières
**Objectif** : combler les 2 manques métier critiques identifiés
en clôture du Sprint 13quater (no-show et refund non
implémentés), et compléter avec une politique d'annulation
configurable. Un PMS ne pouvait pas raisonnablement aller en
prod sans ces mécanismes.

⚠️ Décisions validées : (a) politique no-show par tenant
configurable (none / first_night / full, défaut first_night)
avec surcharge cas par cas par le réceptionniste ; (b) refund
minimaliste V1 (Payment négatif tracé, pas de credit note) ;
(c) politique d'annulation par tenant (flexible / moderate /
strict, défaut flexible) avec frais auto-calculés et
surchargeables (geste commercial) ; (d) facture distincte
émise direct ISSUED pour les frais no-show et annulation.

**Backend — No-show + politique d'annulation [13quinquies-A]**
- [x] Enums `NoShowPolicy` (none / first_night / full) et
  `CancellationPolicy` (flexible / moderate / strict).
- [x] `Tenant::getNoShowPolicy()` / `setNoShowPolicy()` +
  `getCancellationPolicy()` / `setCancellationPolicy()` —
  stockage dans `settings` JSON nullable, défauts cohérents,
  validation par enum. Pas de migration nécessaire.
- [x] `ReservationFeeCalculator` (service pur, sans dépendances
  externes) : `computeNoShowFee()` (match policy → rateXof /
  totalXof / 0), `computeCancellationFee($resa, $policy, $now)`
  qui retourne `{amountXof, reason, hoursBefore}` selon la
  matrice politique × délai. `hoursBetween` via timestamps Unix
  (robuste DST), `normalize` via `bcadd($value, '0', 2)`.
- [x] `FeeInvoiceService::createFeeInvoice($resa, $kind,
  $amountXof, $description, $staff)` — constantes publiques
  `KIND_NO_SHOW` / `KIND_CANCELLATION`, calcul TTC→HT→TVA en
  bcmath, `InvoiceLine` entity séparée (pas JSON), statut
  émis directement à `ISSUED`, audit + Monolog channel
  `business`, timezone explicite Africa/Dakar.
- [x] `ReservationEngine::markNoShow($resa, $staff,
  $policyOverride = null)` : allowed = CONFIRMED + PENDING,
  verrou night audit sur `checkIn`, fallback policy tenant si
  override null, audit log enrichi avec `policy` /
  `overridden`, Mercure publish `reservation.no_show`.
- [x] `ReservationEngine::cancel` étendu : signature
  non-breaking avec `?string $feeOverrideXof = null`, retour
  passe de `Reservation` à `array {reservation, invoice, feeXof,
  feeQuote}`, audit log enrichi avec `feeOverridden`. Garde
  défensive `bcadd($feeOverrideXof, '0', 2)` pour normaliser
  l'override.
- [x] `ReservationController` étendu : `POST /no-show`
  (parsing override via `NoShowPolicy::tryFrom`),
  `GET /cancellation-quote` (dry-run sans effet de bord),
  `POST /cancel` étendu (body `reason` + `feeOverrideXof`,
  reason < 5 chars → fallback "Annulation sans motif détaillé"
  plutôt que 422, pragmatique).
- [x] `TenantSettingsController` — endpoint léger
  `GET /api/tenant/settings` exposant `noShowPolicy` +
  `cancellationPolicy` + `businessDayCutoffHour` + `timezone`
  + `currency`. Lu une fois au mount des modales / vues.
  Volontairement sans `PATCH` en V1 (configuration via UPDATE
  BDD en attendant l'UI dédiée, voir backlog).

**Backend — Refund [13quinquies-B]**
- [x] **Décision documentée** : pas de `PaymentStatus::REFUNDED`.
  Pourquoi : si Payment refund en REFUNDED, alors
  `getCompletedPayments` qui filtre PAID ne le voit pas et
  `getPaidXof` ne le déduit pas → balance fausse. Le pattern
  "Payment négatif + status PAID" fonctionne naturellement
  avec `bcadd` qui somme correctement les négatifs.
- [x] `InvoiceService::refundPayment(Invoice, RefundDTO,
  StaffUser): Payment` — verrou night audit sur business date
  COURANTE (pas sur date facture, cohérent avec
  `recordPayment`), garde anti-over-refund via
  `bccomp($amountXof, $alreadyPaid, 2) > 0`, négativation
  double-normalisée `bcsub('0', bcadd($amount, '0', 2), 2)`,
  Payment créé avec status PAID + montant négatif + reason
  dans notes préfixée `[Remboursement]`, audit log enrichi
  (`amountRefunded` positive saisie + `storedAsXof` négative
  persistée + `previousStatus` / `newStatus`), Mercure publish
  `payment.refunded`.
- [x] `InvoiceService::resolveStatusAfterRefund()` privée :
  CANCELLED reste CANCELLED (return null, statut figé même
  après refund), balance >= total → ISSUED, balance <= 0 →
  PAID, sinon PARTIAL.
- [x] `RefundDTO` (Application/DTO) : `amountXof` (NotBlank +
  numeric + GreaterThan(0)), `method` (NotBlank + Choice via
  `PaymentMethod::values()`), `reason` (NotBlank + Length min 5).
- [x] `PaymentMethod::values()` ajoutée via
  `array_column(self::cases(), 'value')` pour Assert\Choice.
- [x] `InvoiceController::refund` — `POST /api/invoices/{id}/refunds`,
  ParamConverter sur Invoice, validation DTO standard, réponse
  201 avec invoice + refund, RBAC hérité de classe
  `ROLE_ACCESS_BILLING` (réceptionniste + manager + comptable
  peuvent rembourser — le comptable est légitime).

**Frontend [13quinquies-A]**
- [x] Types `financial-policies.ts` (NoShowPolicy,
  CancellationPolicy, TenantSettings, CancellationQuote).
- [x] Service `tenant-settings.service.ts` —
  `tenantSettingsService.get()` qui lit
  `/api/tenant/settings`.
- [x] Service `reservation.service.ts` étendu :
  `getCancellationQuote(id)`, `markNoShow(id, policyOverride?)`,
  `cancel(id, reason, feeOverrideXof?)` (signature évoluée).
- [x] Store `reservations.store.ts` : `cancel` et `markNoShow`
  mis à jour pour propager le payload enrichi (résa + invoice
  + feeXof).
- [x] `MarkNoShowModal.vue` — lazy-load settings tenant à la
  1re ouverture (cache local), reset override à 'tenant' à
  chaque ouverture, mention "Override = geste commercial,
  tracé dans l'audit log" affichée uniquement quand override
  actif, total live recalculé à chaque changement de select.
- [x] `CancelReservationModal.vue` — fetch quote au mount via
  `watch(isOpen)` + `onMounted` (cf. fix robustesse plus
  bas), reset complet à chaque ouverture (reason,
  overrideEnabled, quote, feeInput), checkbox "Modifier le
  montant (geste commercial)" avec input number, `feeOverride`
  envoyé UNIQUEMENT si valeur différente du quote (évite de
  tracer `feeOverridden=true` pour rien), `fmtHours` qui
  affiche en jours si ≥ 48h.
- [x] `ReservationDetailView.vue` — `canMarkNoShow` computed
  (status ∈ {confirmed, pending} ET `checkIn <= todayDakar`
  via `toLocaleDateString('en-CA', { timeZone: 'Africa/Dakar' })`),
  `canCancel` computed (status ∈ {confirmed, pending}), toasts
  adaptatifs selon `result.invoice` (facture créée ou non),
  refresh `load()` après confirm pour voir le nouveau statut +
  facture liée.

**Frontend [13quinquies-B]**
- [x] Service `invoice.service.ts` étendu : `refund(invoiceId,
  payload): Promise<RefundResult>` qui retourne
  `{invoice, refund}` sérialisés.
- [x] `RefundModal.vue` — reset au `watch(isOpen)` avec montant
  pré-rempli au `paidXof`, validation client live
  (`amountValid` : > 0 ET <= paidXof ; `reasonValid` : ≥ 5
  chars ; `formValid`), hint adaptatif selon état (vide /
  négatif / > max / valide avec solde résiduel), bandeau
  info bleu sur "Le remboursement effectif doit être fait
  manuellement par votre agent client. StayOS trace
  l'opération comptablement", methods array statique excluant
  OTA et Mobile Money (cohérent pour un refund), bouton danger
  rouge.
- [x] `InvoiceDetailView.vue` — `canRefund` computed
  (`Number(paidXof) > 0`), bouton "Rembourser" rouge dans la
  barre d'actions, lignes refund visuellement distinctes :
  classe `is-refund` (fond `#FBE5E5` + border-left rouge),
  icône `ti-arrow-back-up`, libellé "Remboursement · via
  {méthode}", raison extraite via `extractRefundReason`
  (retire le préfixe `[Remboursement]`), montant en rouge
  avec préfixe `-`, badge status omis (pour ne pas confondre
  avec un encaissement).

**Hotfix UX intercalé : modale d'annulation listing**
- [x] `ReservationsView.vue` — remplacement complet de la
  modale inline simpliste (juste reason) par le composant
  `CancelReservationModal` partagé. Imports ajoutés
  (`CancelReservationModal` + `useNotificationsStore`).
- [x] State refondu : `cancelTarget` + `cancelReason` (ids
  bruts) remplacés par `cancelTargetRes: Reservation | null`
  + `submittingCancel: boolean` (ressource complète attendue
  par le composant).
- [x] Handler `onConfirmCancel(payload)` propre : appelle
  `store.cancel` avec `feeOverrideXof`, toasts adaptatifs
  selon `result.invoice`, gestion d'erreur via `extractError`.
- [x] Bouton "Annuler" du tableau passe de `cancelTarget = r.id`
  à `cancelTargetRes = r`.
- [x] Modale HTML inline supprimée (~25 lignes), remplacée
  par instanciation propre `<CancelReservationModal />`.

**Fix robustesse intercalé : CancelReservationModal**
- [x] **Diagnostic** : `ReservationsView` utilise
  `v-if="cancelTargetRes"` qui ne monte le composant QUE
  quand truthy, avec `:is-open="cancelTargetRes !== null"`
  donc `isOpen=true` au moment du mount. Le
  `watch(() => props.isOpen)` ne capture pas la valeur
  initiale (il attend une transition `false → true`), donc
  `loadQuote()` n'était jamais appelé → `quote` restait
  `null` → body de la modale VIDE (rien ne match dans les
  `v-if`/`v-else-if` du template). `MarkNoShowModal` avait
  déjà la bonne protection via `onMounted`, mais
  `CancelReservationModal` n'avait QUE le `watch`.
- [x] Ajout import `onMounted` + ajout d'un `quoteError`
  ref pour état d'erreur visible.
- [x] `loadQuote()` robustifiée avec `catch (e: unknown)` qui
  extrait le message via `(e as any)?.response?.data?.error`
  avec fallback FR explicite, reset `quote.value = null`
  pour ne pas afficher du contenu obsolète après erreur.
- [x] Ajout du `onMounted` défensif avec commentaire qui
  documente précisément pourquoi le hook existe.
- [x] Template : nouvelle branche `v-else-if="quoteError"`
  entre loading et quote, avec icône `ti-alert-circle`,
  message, et bouton "Réessayer" qui rappelle `loadQuote()`.
- [x] Style `.error-box` ajouté, palette cohérente avec
  `.fee-row` (rouge `#B83232` / `#8C2424` / `#F5DADA`).

**Tests**
- [x] Unit `ReservationFeeCalculatorTest` — matrice
  complète des politiques × délais (3 politiques × N délais
  pour cancel, 3 politiques pour no-show), `hoursBetween`
  avec différents écarts incluant DST.
- [x] Functional `NoShowTest` (@group integration) :
  `testReceptionistCanMarkNoShow`,
  `testNoShowWithFirstNightPolicyCreatesInvoice`,
  `testNoShowWithNoneOverrideCreatesNoInvoice`,
  `testNoShowOnCheckedInReservationRefused` (422),
  `testNoShowOnPastClosedDayRefused` (verrou),
  `testHousekeeperCannotMarkNoShow` (403).
- [x] Functional `CancellationWithFeesTest` (@group integration) :
  `testCancellationFlexibleNeverFees`,
  `testCancellationStrictAlwaysFee`,
  `testCancellationModerateMoreThan48h_NoFee`,
  `testCancellationModerate24To48h_FirstNight`,
  `testCancellationModerateLessThan24h_Total`,
  `testFeeOverrideUsesProvidedAmount`,
  `testGetCancellationQuoteDoesNotMutate`,
  `testCancellationCreatesInvoiceWhenFeesPositive`,
  `testCancellationCreatesNoInvoiceWhenFeesZero`.
- [x] Functional `RefundTest` (@group integration) — 9 tests :
  `testReceptionistCanRefund`,
  `testRefundCreatesNegativePayment` (vérification BDD
  directe que le 2e Payment a bien `'-20000.00'`),
  `testRefundUpdatesInvoiceStatusToPartial`,
  `testRefundFullPaymentReturnsStatusToIssued`,
  `testRefundExceedingPaidIsRefused` (422 BUSINESS_RULE),
  `testRefundRequiresReasonMinLength` (422 VALIDATION_ERROR),
  `testRefundOnUnpaidInvoiceRefused`,
  `testRefundOnCancelledInvoiceStaysCancelled` (statut figé),
  `testRefundIsBlockedByNightAuditLockOnToday`,
  `testHousekeeperCannotRefund` (403).
- [x] Stratégie cleanup propre : helper `seedInvoice` avec
  préfixe `FAC-REFTEST-` pour cleanup ciblé, `tearDown` qui
  purge payments + invoices + audit_logs `payment.refunded`
  + daily_closes.

**Ajouts notables (Sprint 13quinquies) — décisions et apprentissages**
- [x] **Décision « Payment négatif + status PAID pour
  matérialiser une sortie de caisse »** : pas de
  `PaymentStatus::REFUNDED`. Le pattern simple "Payment avec
  amount négatif et status PAID" fonctionne naturellement
  avec `bcadd` qui somme correctement les négatifs. Discrimine
  via `amount < 0` côté UI. Si un nouveau status `REFUNDED`
  était ajouté, le filtre `getCompletedPayments` (qui retient
  PAID uniquement) ne le verrait pas et `getPaidXof` serait
  faux. Pattern à reproduire pour toute "sortie" comptable
  qui n'a pas besoin d'être un objet métier séparé.
- [x] **Décision « facture distincte pour les frais »** : un
  no-show ou une annulation avec frais crée une NOUVELLE
  facture (status ISSUED direct, ligne typée
  `kind: no_show | cancellation`). Pas de modification de la
  facture draft existante (qui peut ne pas exister, ex : résa
  annulée avant check-in). Plus propre conceptuellement et
  permet de discriminer le revenu type "séjour" vs "frais"
  dans les rapports futurs.
- [x] **Décision « dry-run quote avant action mutante »** :
  `GET /cancellation-quote` calcule les frais SANS rien
  modifier en BDD. L'UI fetch le quote au mount de la modale,
  affiche les conséquences (politique + délai + frais), puis
  l'utilisateur confirme via `POST /cancel`. Pattern cohérent
  avec `GET /night-audit/current` vs `POST /close` du Sprint
  13quater. À reproduire pour toute action mutante coûteuse
  ou irréversible.
- [x] **Décision « override commercial tracé »** : à chaque
  fois que l'utilisateur surcharge un calcul auto (politique
  no-show, montant des frais d'annulation), c'est marqué dans
  l'audit log via `overridden: true` ou `feeOverridden: true`.
  Permet a posteriori de distinguer "calcul automatique
  appliqué" vs "geste commercial décidé par le réceptionniste".
- [x] **Découverte « composant modal doit être robuste à son
  pattern d'instanciation »** : un composant qui dépend
  uniquement de `watch(() => props.isOpen)` casse quand le
  composant est monté via `v-if` avec `isOpen=true` à la
  création. Solution : combiner `onMounted` (capture la valeur
  initiale) + `watch` (capture les changements ultérieurs).
  Pattern à appliquer à tous les composants modales / popovers
  qui font du fetch piloté par `isOpen`. `MarkNoShowModal`
  avait déjà la bonne combinaison ; `CancelReservationModal`
  l'a maintenant aussi.
- [x] **Découverte « état d'erreur visible obligatoire pour
  les modales avec fetch »** : un `try / finally` sans `catch`
  laisse le composant dans un état muet quand le fetch échoue.
  Pattern à appliquer partout : `try / catch / finally` avec
  un état `xxxError: ref<string | null>` exposé dans le
  template via une branche `v-else-if="xxxError"` + bouton
  "Réessayer". Le silence est un bug.

**Livrable** : le cycle financier complet est désormais
fonctionnel. Le réceptionniste peut marquer un client absent
(no-show) avec facturation configurable, annuler une
réservation avec calcul automatique des frais selon la
politique tenant, et le manager peut effectuer un
remboursement tracé sur n'importe quelle facture. L'UI est
cohérente entre listing et fiche détail, robuste aux erreurs
réseau, et le calcul des frais est toujours montré à
l'utilisateur AVANT confirmation (pattern dry-run). Le PMS est
maintenant prêt à passer en production (Sprint 14) sans aucun
trou métier financier.

---

### ✅ Sprint 14-A.1 — Dettes critiques avant prod
**Objectif** : nettoyer les dettes techniques accumulées au
fil des sprints précédents avant la mise en production.
6 chantiers indépendants, chacun livré dans une session
séparée pour rester vérifiable.

**Bilan** : 193 → 413 tests (× 2.14), 0 régression introduite,
1 régression cachée révélée et corrigée
(`ReservationPromoTest` dates).

⚠️ Sprint livré en 6 sous-prompts itératifs. La structure
ci-dessous reflète cette réalité — utile pour relire
l'historique :
1. **Chantier 2** : scripts npm + tsconfig + eslint
2. **Chantier 1** : retrait `@group integration`
3. **Chantier 5** : audit `catch (\Throwable)` silencieux
4. **Chantier 4** : `OnboardingService` transactionnel
5. **Chantier 6** : audit paramètres YAML hardcodés
6. **Chantier 3** : `FeatureVoter` + `FeatureGuardTest`

**Chantier 2 — Scripts npm + tsconfig + eslint**
- [x] `frontend/tsconfig.json` créé (extends
  `@vue/tsconfig/tsconfig.dom.json`, alias `@/*` → `./src/*`,
  `noUnusedLocals: false` pour ne pas bloquer le build)
- [x] `frontend/eslint.config.js` créé (flat config eslint 9,
  règles permissives V1)
- [x] `"type": "module"` ajouté dans `package.json`
- [x] Cible Makefile `npm-type-check` ajoutée
- [x] `make npm-type-check`, `make npm-build`, `make npm-lint`
  tous verts (0 erreur, 6 warnings ESLint tolérés)
- [x] 5 bugs typés TypeScript corrigés au passage (à valider
  robustesse en polish 14-A.3)
- **Décision** : exclure `vite.config.ts` du tsconfig racine
  plutôt que d'avoir un `tsconfig.node.json` séparé — plus
  simple, on perd juste le type-check de ce fichier stable.

**Chantier 1 — Retrait `@group integration`**
- [x] 44 annotations `@group integration` retirées de
  `backend/tests/`
- [x] 1 directive `<group>integration</group>` retirée du
  bloc `<exclude>` de `phpunit.xml.dist`
- [x] Cible Makefile `test-integration` conservée (utile en
  V2 pour re-grouper des tests externes)
- [x] Tests passés de 193 → 401 (+208 tests intégrés à la
  suite par défaut)
- **Régression cachée révélée** : `ReservationPromoTest`
  avec des dates non actualisées — fixée dans ce chantier.

**Chantier 5 — Audit `catch (\Throwable)` silencieux**
- [x] 31 occurrences auditées, classifiées en 3 types
- [x] 4 TYPE 1 (silencieux corrigés en TYPE 2) :
  - `HealthController` DB probe + Redis probe → ajout
    `logger->error()` avec contexte (`error`, `class`)
  - `SubscriptionController::computeUsage` rooms + staff_users
    → ajout `logger->warning()` avec contexte enrichi
    (`tenant slug` via `tenantContext->has()`)
- [x] 2 TYPE 2 (loggé, silence justifié) :
  `CheckSubscriptionsHandler` et `AbonnementService::checkExpirations`
  (isolation des erreurs par tenant dans un batch nocturne)
- [x] 25 TYPE 3 (rethrow ou transformation HTTP) RAS
- [x] Document d'audit créé :
  `backend/docs/catch-audit-2026-14.md` (inventaire + patches)
- **Recommandation backlog** : règle PHPStan ou test
  reflection-based qui bloque les catch silencieux dans les
  futurs PRs.

**Chantier 4 — `OnboardingService` transactionnel**
- [x] Helper privé `dropSchemaSafely()` créé avec validation
  regex anti-injection + try/catch sur le DROP lui-même
- [x] `register()` refactorée avec
  `beginTransaction()`/`commit()`/`rollback()`, schema name
  capturé après le flush du tenant, OTP positionné APRÈS
  commit (panne Mailjet ne doit pas annuler l'inscription)
- [x] `provision()` refactorée avec le même pattern (sans
  OTP), seed à l'intérieur du search_path tenant
- [x] `LoggerInterface` injecté dans le constructor
- [x] 3 tests fonctionnels créés (`OnboardingTransactionalTest`) :
  - `testRegisterRollsBackWhenAbonnementServiceFails`
    (mock `AbonnementService::createTrial` → throw)
  - `testProvisionRollsBackWhenSubscriptionFails`
    (mock `AbonnementService::createActive` — adaptation
    faite parce que `TenantSeedService` est `final` et donc
    non-mockable par PHPUnit 11)
  - `testProvisionWithUnknownPlanDoesNotProvisionSchema`
    (cas RÉEL sans mock — plan inexistant)
- [x] Pattern `baselineOrphanSchemas` au setUp pour ne
  mesurer QUE les nouveaux orphelins créés par le test
  (évite faux positifs en CI dus aux résidus d'autres tests)
- [x] 404 tests verts, 0 orphan détecté par
  `cleanup-orphans --dry-run`

**Chantier 6 — Audit paramètres YAML hardcodés**
- [x] 25 fichiers YAML inventoriés (`services.yaml`,
  `routes.yaml`, 22 packages, 1 override test)
- [x] 19 références `%env(VAR)%` croisées avec `backend/.env`
  — toutes documentées
- [x] 0 migration nécessaire (fix Sprint 12 avait fait le
  ménage principal sur `default_backend_url`)
- [x] Document d'audit créé :
  `backend/docs/yaml-audit-2026-14.md` (tableau exhaustif
  par fichier + synthèse des constantes légitimes)
- [x] Cas marginal documenté : `VAR_DUMPER_SERVER`
  (`debug.yaml` dev-only) — défaut interne Symfony
  Debug Bundle `127.0.0.1:9912`, pas besoin d'ajouter à
  `.env`
- **Recommandations backlog** : pre-commit hook yaml↔.env,
  cleanup nelmio_cors via `when@dev:`/`when@prod:`, audit
  `.env` côté prod au déploiement Heroku.

**Chantier 3 — FeatureVoter + FeatureGuardTest**
- [x] `FeatureVoter` créé
  (`Platform/Subscription/Security/Voter/`)
- [x] 11 call sites refactorés (le backlog en estimait 4,
  découverte d'audit à 11) :
  - `RateController` : 6 méthodes
    (create/update/delete Plan + Seasonal) ←
    `FEATURE_revenue_management`
  - `PromotionController` : 3 méthodes (create/update/delete)
    ← `FEATURE_revenue_management`
  - `DashboardController` : 2 méthodes (report + export) ←
    `FEATURE_advanced_reports`
- [x] Import `FeatureChecker` et injection retirés des 3
  controllers (plus utilisés en direct)
- [x] `FeatureGuardTest` global créé (13 tests) :
  - 5 tests STARTER strict (`assertFeatureBlocked` : 403 +
    `PLAN_LIMIT` + message contenant « fonctionnalité »)
  - 6 tests STARTER lax (`assertEndpointBlocked` : 403 OU
    404 NOT_FOUND — voir leçon d'archi ParamConverter)
  - 2 tests PRO (`assertFeatureAllowed` :
    `assertNotSame(403)`)
- [x] Ancien `tests/Functional/Api/Subscription/FeatureGuardTest.php`
  (4 tests) supprimé car entièrement subsumé
- [x] 413 tests verts (404 + 13 − 4)

**Décisions techniques (Sprint 14-A.1)**
- **Pattern `FEATURE_<name>` au lieu de `('FEATURE', '<name>')`** :
  la syntaxe orthodoxe ne fonctionne pas en l'état — Symfony
  interprète le second argument d'`IsGranted` comme un nom
  d'argument du contrôleur. Contournements possibles :
  `new Expression("'...'")` (requiert
  `symfony/expression-language`) ou encoder la feature dans
  le nom (`FEATURE_<name>` avec
  `ATTRIBUTE_PREFIX = 'FEATURE_'` + `str_starts_with` +
  `substr`). Choix retenu : le second, sans dépendance
  ajoutée. Documenté dans le DocBlock du voter.
- **Voter qui THROW au lieu de RETURN false** : préserve le
  message custom de `FeatureNotAvailableException` + le code
  HTTP 403 + le code métier `PLAN_LIMIT` mappé par
  `ApiExceptionListener`. Retourner false aurait fait lever
  `AccessDeniedException` générique avec un message
  "Accès refusé" sans le nom de la feature.
- **DROP SCHEMA défensif dans le rollback `OnboardingService`** :
  même si en théorie PostgreSQL annule le `CREATE SCHEMA`
  lors d'un rollback, le Sprint 13ter a observé 24 schemas
  orphelins en pratique. Défense en profondeur — on combine
  transaction Doctrine + DROP explicite avec validation
  regex anti-injection.
- **OTP `register()` placé APRÈS `commit()`** : un échec
  Mailjet ne doit pas annuler l'inscription du tenant. Si
  OTP rate, l'utilisateur pourra demander un renvoi via l'UI.
  Pattern cohérent avec les autres flows secondaires
  non-bloquants (Sprint 12).
- **`baselineOrphanSchemas` pattern dans les tests** :
  capturer au setUp les orphelins préexistants pour ne
  mesurer QUE les nouveaux créés par le test. Évite que
  les tests échouent à cause de résidus d'autres tests qui
  ne nettoient pas.

**Livrable** : code production-ready côté qualité technique.
Suite de tests honnête (plus de masquage par
`@group integration`), feature-gating déclaratif via voter,
onboarding transactionnel avec garde-fou anti-injection,
build TypeScript fonctionnel, 2 documents d'audit comme
artéfacts de revue.

---

### ✅ Sprint 14-A.2 — UI manager politiques financières
**Objectif** : permettre au manager de configurer via UI les
3 paramètres financiers stockés dans `tenant.settings` JSON
(no_show_policy, cancellation_policy, business_day_cutoff_hour)
au lieu de modifier en BDD directement. Sprint court (~1 jour
effectif) avec un seul périmètre cohérent.

**Backend**
- [x] `App\Platform\Tenant\Application\DTO\UpdateTenantSettingsDTO`
  créé. Tous les champs OPTIONNELS pour PATCH partiel. Pattern
  `mixed` (au lieu de `?int`) sur `businessDayCutoffHour` pour
  permettre à `Assert\Type('integer')` de rejeter proprement
  les chaînes avec un message FR au lieu d'un TypeError PHP
  natif. Messages d'erreur FR custom sur les 3 contraintes.
- [x] `TenantSettingsController::updateSettings` ajouté —
  endpoint `PATCH /api/tenant/settings`. RBAC en vérif manuelle
  (`isGranted('ROLE_MANAGER')`) plutôt qu'attribut `IsGranted`
  au niveau méthode pour préserver le message FR custom et le
  code d'erreur `ACCESS_DENIED` (pattern cohérent avec
  `FeatureVoter` du Sprint 14-A.1).
- [x] Pattern `array_key_exists` au lieu de `isset` pour
  distinguer "champ absent" de "champ explicitement à null"
  (important pour un PATCH partiel).
- [x] Helper privé `serializeSettings()` factorise la
  sérialisation GET et PATCH retour (DRY).
- [x] Pas de cast int sur `businessDayCutoffHour` : laisse
  `Assert\Type` rejeter `"5"` (string) avec un 422
  VALIDATION_ERROR plutôt qu'un cast silencieux à `5`.
- [x] Audit log `tenant.settings_updated` avec diff
  `before`/`after` uniquement sur les champs CHANGÉS. Skippé
  si aucun changement effectif (pas d'entrée fantôme).
- [x] Méthode statique `values()` ajoutée aux enums
  `NoShowPolicy` et `CancellationPolicy` (pattern hérité de
  `PaymentMethod::values()` du Sprint 13quinquies-B).
- [x] `DailyCloseService::getCurrent()` étendu — `cutoffHour`
  ajouté dans les 4 chemins de retour (calculé une seule fois
  en haut, injecté partout). PHPDoc mis à jour.

**Frontend**
- [x] `tenant-settings.service.ts` étendu avec `update()` +
  type `TenantSettingsUpdatePayload` qui dérive ses champs de
  `TenantSettings` (DRY).
- [x] Type `NightAuditCurrent` étendu avec `cutoffHour: number`
  + commentaire référençant le Sprint 14-A.2.
- [x] `HotelConfigurationView.vue` étendu avec un 4e onglet
  "Finances" (passage de 3 → 4 onglets) :
  - State encapsulé : `financeSettings` (source de vérité
    serveur), `financeDraft` (édition locale),
    `financeHasChanges` computed sur les 3 champs,
    `financeSaving` / `financeError`.
  - 3 selects (no-show 3 options, cancellation 3 options,
    cutoff 24 valeurs 0-23h) avec hints explicatifs riches
    (matrice de la politique modérée détaillée par exemple).
  - Boutons "Annuler les modifications" / "Enregistrer"
    disabled si pas de changement effectif ou sauvegarde en
    cours.
  - Gestion des 3 états visuels : erreur (avec bouton
    Réessayer) / prêt / chargement.
  - CSS dédié `.form-row .input-label` : `text-transform:none`,
    `font-size:13px` (plus lisible que les uppercase 11px des
    tables).
- [x] `NightAuditView.vue` : fix de la dette "5h" hardcodée —
  remplacé par `{{ current.cutoffHour }}` (à 2 endroits dans
  la même phrase).
- [x] `onMounted` de `HotelConfigurationView` étendu avec
  `refreshFinanceSettings()` dans le `Promise.all` (parallélise
  les 5 requêtes initiales).

**Tests**
- [x] `tests/Functional/Api/Tenant/TenantSettingsControllerTest`
  créé — 10 tests :
  - `testGetSettingsRequiresAuthentication` (401 sans JWT)
  - `testGetSettingsReturnsAllFields` (200 + 5 champs)
  - `testPatchSettingsRequiresManagerRole` (403 réceptionniste)
  - `testPatchSettingsAcceptsFullPayload` (200 + 3 changements
    en BDD + audit log avec 3 diffs)
  - `testPatchSettingsAcceptsPartialPayload` (200 + 1 seul
    champ changé + audit log avec 1 seul diff)
  - `testPatchSettingsRejectsInvalidEnum` (422
    VALIDATION_ERROR)
  - `testPatchSettingsRejectsInvalidCutoffHour` (422 range)
  - `testPatchSettingsRejectsEmptyPayload` (422 BUSINESS_RULE)
  - `testPatchSettingsNoOpDoesNotWriteAudit` (200 mais 0 audit
    log créé)
  - `testPatchSettingsIsCrossTenantIsolated` (Savana change
    n'impacte pas Villa Collines)
- [x] `setUp/tearDown` : restore les valeurs par défaut sur
  les 2 tenants (savana + villa-collines) via JSON merge
  operator PostgreSQL `||` (préserve les autres clés du
  settings JSON, ne pollue pas les autres tests) +
  `em->clear()` pour invalider le cache Doctrine. Purge des
  audit logs `tenant.settings_updated` du schema savana.
- [x] PAS de `@group integration` (cohérent avec Chantier 1
  du Sprint 14-A.1).
- [x] 413 → 423 tests verts (+10).

**Décisions techniques (Sprint 14-A.2)**
- **`mixed` au lieu de `?int` pour les champs DTO validés
  par `Assert\Type`** : si `?int`, l'assignation `$dto->x = "25"`
  throw un `TypeError` PHP natif AVANT que `Assert\Type` puisse
  rejeter proprement avec un message FR. Avec `mixed`,
  l'assignation passe, le validateur retourne un 422
  VALIDATION_ERROR explicite. Pattern à reproduire pour tout
  DTO Symfony Validator qui doit valider des types qui ne
  matchent pas le typage PHP.
- **`array_key_exists` au lieu de `isset` pour les PATCH
  partiels** : `isset` retourne `false` pour une clé
  explicitement à `null`, ce qui empêche de distinguer "champ
  absent" de "champ explicitement nullifié". Pour un PATCH
  REST où l'absence et le null peuvent avoir des sémantiques
  différentes, `array_key_exists` est obligatoire.
- **RBAC en vérif manuelle plutôt qu'attribut `IsGranted`** :
  même pattern que le `FeatureVoter` du Sprint 14-A.1 — quand
  on veut un message FR custom et un code d'erreur API custom
  (`ACCESS_DENIED` ici, `PLAN_LIMIT` pour le voter), la vérif
  manuelle dans le controller est préférable à l'attribut
  Symfony qui retourne un `AccessDeniedException` générique.
- **Audit log skippé si aucun changement effectif** : un PATCH
  avec les MÊMES valeurs que les valeurs courantes retourne 200
  mais n'écrit AUCUNE entrée d'audit log. Évite la pollution
  par des updates fantômes. Pattern cohérent avec
  `SuperAdminController::updateTenant` (Sprint 13bis-B).
- **`cutoffHour` calculé une seule fois en haut de
  `getCurrent()`** : variable locale réutilisée dans les 4
  chemins de retour (au lieu de 4 appels
  `$tenant->getBusinessDayCutoffHour()`). Lecture plus claire
  et perfs marginalement meilleures.

**Livrable** : un manager hôtel peut désormais configurer les
3 politiques financières (no-show, annulation, cutoff
comptable) depuis l'UI sans toucher à la BDD. Les changements
se propagent immédiatement aux modales d'annulation (calcul
des frais selon la nouvelle politique) et à la vue Night
Audit (affichage du cutoff configuré). Plus aucune dette type
"valeur hardcodée côté front décorrélée du backend" sur les
politiques financières.

---

### 🔧 Hotfix critique — HotelProfile à l'onboarding (entre 14-A.2 et 14-A.3)

**Contexte** : bug "Profil hôtel introuvable" découvert pendant
le smoke test du Sprint 14-A.2 sur le tenant balladin. Toute
opération passant par `ReservationEngine::computeQuote()`
(création/modification de réservation, no-show avec frais,
annulation avec frais) lançait une `BusinessRuleException`.

**Cause racine** : `OnboardingService::register()` (inscription
publique) ET `OnboardingService::provision()` (création
SuperAdmin) NE créaient PAS de `HotelProfile`. `TenantSeedService`
non plus, peu importe le template. Le `HotelProfile` était
créé UNIQUEMENT par `HotelDataFixtures` (fixtures dev) — d'où
le fonctionnement OK de Savana/Villa Collines en local mais
casse de balladin et de tout futur tenant créé en prod.

**Impact pré-correction** : bug bloquant pour la prod. Aucun
client provisionné via SuperAdmin ne pouvait faire de
réservation.

**Correction (2 niveaux)** :
- [x] Commande Symfony `stayos:tenant:ensure-hotel-profile`
  (`Platform/Tenant/Application/Command/EnsureHotelProfileCommand`)
  — idempotente, mode `--dry-run`, traite tous les tenants
  non-CHURNED. Pattern try/finally sur SET search_path +
  `em->clear()` entre tenants. Réparation curative pour
  balladin + test-new-tenant + outil de réparation V2.
- [x] Modification `OnboardingService::register/provision`
  pour créer un `HotelProfile` par défaut dans le même
  `try { SET search_path }` block que le StaffUser, AVANT
  le seed (cohérence si un futur seed dépendait du profil).
  Le `name` du HotelProfile = `tenant.name`, autres champs
  aux defaults entité (`country='SN'`, `checkInTime='14:00'`,
  `checkOutTime='11:00'`).
- [x] Tests étendus : `testRegisterCreatesHotelProfile` +
  `testProvisionCreatesHotelProfile` + helper privé
  `cleanupTenant($tenantId, $schemaName)` qui purge
  subscriptions → tenants → DROP SCHEMA (avec regex
  anti-injection) pour les tests success.

**Validation runtime** : dry-run → balladin=WOULD CREATE ✓,
apply → CREATED ✓, re-run → tous OK (idempotence
confirmée). 425 tests verts. Smoke test création de
réservation sur balladin : OK.

**Leçon retenue** : voir leçons d'architecture (tester
l'onboarding end-to-end avec un tenant fraîchement
provisionné, pas seulement avec les fixtures dev).

---

### ✅ Sprint 14-A.3 — Cohérence et polish

**Objectif** : nettoyer les ~17 dettes mineures accumulées
avant la prod (audit logs, normalisation, UX frontend,
hygiène config). Sprint dense découpé en **5 sous-paquets**
attaqués séquentiellement pour rester vérifiable :

1. **A.1 — Cohérence audit logs** (3 dettes)
2. **A.2 — Cohérence métier backend** (3 dettes + 1 obsolète
   retirée)
3. **B.1 — Cohérence UX frontend** (4 dettes)
4. **B.2 — Hygiène technique frontend/config** (4 dettes)
5. **C.1 — Outillage et bugs** (5 dettes)

**Bilan global** : 429 tests verts (+5 nouveaux), 1609
assertions, 2 runs successifs identiques (déterminisme),
0 régression, 16 dettes traitées.

---

**A.1 — Cohérence audit logs (3 dettes, 4+11+11 call sites)**
- [x] Fix `entityId='new'` dans audit logs (4 services) :
  `ReservationEngine::create`, `DailyCloseService` (×2 :
  close + reopen pattern), `InvoiceService::refundPayment`,
  `FeeInvoiceService`. Remplacement de `'new'` littéral par
  `(string) $entity->getId()` après le `persist()` (l'ID est
  disponible dès le persist avec UuidV4Generator custom
  Doctrine, pas besoin d'attendre le flush).
- [x] Normalisation `entityType` PascalCase (11 call sites) :
  `daily_close → DailyClose`, `staff_invitation →
  StaffInvitation`, `staff_user → StaffUser`, `tenant →
  Tenant`. Convention alignée sur les noms de classes
  Doctrine (cohérence avec `Reservation`, `Invoice`,
  `HotelProfile` déjà en PascalCase).
- [x] Uniformisation contexte logs `catch` (11 call sites) :
  ajout de `'class' => $e::class` partout (suite Chantier 5
  Sprint 14-A.1). `MercurePublisher` non touché — il utilise
  déjà `get_class($e)`, équivalent fonctionnel.
- [x] 2 tests dédiés : `testCreateReservationLogsAuditWithRealEntityId`
  (capture des arguments via `willReturnCallback`,
  assertion `assertNotSame('new')` + `assertSame((string) $reservation->getId())`)
  et `testRefundLogsAuditWithRealEntityId`.

**A.2 — Cohérence métier backend (3 dettes + 1 obsolète)**
- [x] Renommage `cancelledTenantsCount` → `churnedTenantsCount`
  dans `PlatformMetrics` DTO + `PlatformMetricsService::compute`
  + frontend (`PlatformMetricsView`, `TenantsListView`, type
  `superadmin.ts`). Libellé UI passé de "Désabonnés" à
  "Résiliés" (cohérent avec "Actifs"/"Suspendus"). Le champ
  comptait en réalité les tenants `CHURNED` — nom maintenant
  aligné avec ce qu'il compte. Pas d'alignement enum
  CHURNED/cancelled (sémantiquement différents — état tenant
  final vs état subscription transactionnel).
- [x] Factorisation `SaasInvoiceService::buildTenantUrl` →
  `TenantUrlBuilder::build` (2 call sites + suppression
  méthode privée + retrait du commentaire de dette dans
  `TenantUrlBuilder`). Le builder est désormais utilisé par
  `SaasInvoiceService` et `EmailService::sendStaffInvitation`.
- [x] Garde-fou backend no-show futur :
  `ReservationEngine::markNoShow` refuse désormais une résa
  dont `checkIn > businessDate` (comparaison `>` strict,
  `checkIn = today` reste valide). Défense en profondeur
  côté serveur (le frontend filtrait déjà via
  `canMarkNoShow`). Test
  `testNoShowOnFutureCheckInRefused` ajouté avec helper
  `seedConfirmedArrivingInFuture(+5 jours)`.
- [x] **Dette obsolète identifiée et retirée du backlog** :
  "MOBILE_MONEY CHECK constraint SQL manquante" — vérification
  faite, la migration `Version20260520170000UpdatePaymentMethodCheck`
  (Sprint 7) inclut déjà `'mobile_money'` dans le CHECK.
  Dette résolue depuis longtemps, conservée par inertie
  dans le backlog. Retirée à la clôture.

**B.1 — Cohérence UX frontend (4 dettes)**
- [x] Uniformisation feedback `flash() / flashError()` →
  `pushUiToast()` (4 vues refactorées) : `InvoiceDetailView`,
  `TenantDetailView`, `StaffListView`, `TaskCard.vue`
  (housekeeping). Refs `successMsg / errorMsg` retirées,
  divs styled inline retirés, CSS dédié retiré. La
  distinction "feedback transitoire" (→ toast) vs "état de
  page" (`paymentReturn` bandeau persistant, `error`
  "Facture introuvable") est préservée.
- [x] Fix anti-spam toasts rafale 4+ dans
  `notifications.store.ts` : ajout d'un champ explicite
  `groupedCount?: number` sur `ToastEntry`. La logique
  `pushToast` détecte d'abord un toast déjà-groupé du même
  type dans la fenêtre (incrémente son count + met à jour
  le titre), puis seulement après applique la logique
  existante de grouping initial. Le 4e+ toast d'une rafale
  est désormais correctement fusionné.
- [x] `lastEffectiveClose` indépendant de la pagination dans
  `NightAuditView.vue` : `computed` remplacé par un `ref`
  mis à jour UNIQUEMENT dans `reload()` (qui force
  `page.value = 1`). `changePage` ne le touche plus. Le
  bouton "Réouvrir" cible toujours la vraie dernière close,
  même en page 2+.
- [x] Doublon `disconnect()` au logout retiré dans
  `auth.store.ts` : l'appel explicite à
  `useNotificationsStore().disconnect()` est retiré du
  `logout()`. Le `watch(auth.isAuthenticated)` dans
  `App.vue` reste la voie canonique (couvre tous les cas :
  logout manuel, expiration JWT, redirection 401).

**B.2 — Hygiène technique frontend/config (4 dettes)**
- [x] 6 warnings ESLint `catch (e: any)` → `catch (e: unknown)`
  + helper `extractError(e, fallback)` avec type-guard. 6 → 0
  warnings.
- [x] Audit des 5 bugs TypeScript du Chantier 2 (Sprint
  14-A.1) : Sprint 14-A.1 non committé en git, audit basé
  sur l'état présent. 3 patterns identifiés et jugés
  acceptables (`api.service.ts` Promise élargie + cast
  `as never` sur axios overloads, `TenantDetailView` cast
  `as unknown as { timezone?: string }`). Recommandation
  noté au backlog : ajouter `timezone?: string` au type
  `TenantDetail` à la prochaine touche du module SuperAdmin.
- [x] TVA en bcmath dans snapshot night audit :
  `InvoiceRepository::countAndSumIssuedForDate` étendu avec
  un 3e champ `vat` (`COALESCE(SUM(i.taxXof), 0)`).
  `DailyCloseService::buildSnapshot` injecte `'vatXof' =>
  $invoicesIssued['vat']` dans `snapshot.invoices`. Template
  Twig `daily_close_pdf.html.twig` lit `invoices.vatXof|default(null)`
  → affiche "Dont TVA" (sans `≈`) si présent, fallback float
  TTC/1.18×0.18 pour les snapshots pré-14-A.3 (invariant
  d'immutabilité respecté). Test
  `testSnapshotIncludesExactVatXofFromIssuedInvoices` avec
  seed 3 invoices et taxXof connus.
- [x] Refacto `nelmio_cors.yaml` via `when@dev/test/prod` :
  section principale avec règles communes (methods, headers,
  max_age) sans aucun `allow_origin`. Les origins sont
  scopés par environnement. Pas d'origin dev exposable en
  prod par inadvertance.

**C.1 — Outillage et bugs (5 dettes)**
- [x] Flakiness `CancellationWithFeesTest` corrigée — la
  colonne `reservations.check_in` est `date_immutable` (DATE
  sans heure), donc le helper qui formatait `now + Xh` →
  `Y-m-d` perdait la composante horaire et retombait à
  minuit. Helper réécrit pour calculer
  `daysAhead = max(1, ceil(hoursOffset / 24))` et poser
  `check_in = today + N jours à minuit Africa/Dakar`. Bande
  visée garantie peu importe l'heure d'exécution. 2 runs
  successifs identiques (déterminisme prouvé).
- [x] Fix Lexik rate limiter login : `LoginRateLimitListener`
  (`Platform/Auth/Infrastructure/EventListener/`) sur l'event
  `lexik_jwt_authentication.on_authentication_failure`.
  Détection de `TooManyLoginAttemptsAuthenticationException`
  sur deux chemins (`$exception` direct OU `getPrevious()`).
  Renvoi 429 RATE_LIMITED. Pool dédié
  `cache.rate_limiter: filesystem` ajouté en env test (le
  cache `array` ne survit pas au reboot kernel).
  `testLoginRateLimitAfterFiveAttempts` réactivé (3 → 2
  tests skippés) avec `$client->disableReboot()` + email
  unique + clear `cache.rate_limiter` en setUp pour
  isolation.
- [x] Suppression PUT legacy `/api/rooms/types/{typeId}` :
  `RoomController::updateType` retirée + import
  `UpdateRoomTypeDTO` retiré. `RoomTypeController::update`
  (`PUT /api/room-types/{id}`) reste canonique. GET legacy
  `/api/rooms/types` conservé pour l'instant (à auditer
  séparément — entrée backlog).
- [x] `#[\Deprecated]` sur `StaffUser::eraseCredentials()` :
  attribut natif PHP 8.4 (`since: '7.3'`) + PHPDoc
  `@deprecated`. La méthode reste vide (comportement
  préservé). Sera retirée à la migration Symfony 8.
- [x] Élimination deprecation `exposeSecurityErrors`
  (476×/run → 0) : `symfony/security-bundle 7.3` passe
  `param('security.authentication.hide_user_not_found')`
  (boolean) à `AuthenticatorManager::__construct` dont la
  signature attend désormais `ExposeSecurityLevel|bool`.
  Override du paramètre dans `services.yaml` avec
  `!php/enum Symfony\Component\Security\Http\Authentication\ExposeSecurityLevel::None`
  (équivalent sémantique de `hide_user_not_found: true`).
  Comportement de sécurité préservé.

**Livrable** : projet désormais cohérent (audit logs,
vocabulaire métier, UX), robuste (TVA exacte, garde-fous
serveur, anti-spam toasts), hygiénique (0 warning ESLint,
0 deprecation `exposeSecurityErrors`, configs Symfony
`when@*`), déterministe (tests temporels figés, isolation
Redis rate-limiter), outillé (commande `ensure-hotel-profile`,
listener rate limit).

Le projet est prêt à passer au Sprint 14-B (sécurité
production).

---

### ✅ Sprint 14-B — Sécurité et performance

**Objectif** : durcir l'application pour la production.
Sprint découpé en **2 grands volets** attaqués séquentiellement,
chacun en sous-paquets vérifiables :

1. **B.1 — Sécurité** (headers HTTP, rate limiting global,
   vérification IPN Paydunya)
2. **B.2 — Performance & monitoring** (Mercure prod, cache
   Redis KPIs, indexes PostgreSQL)

**Bilan global** : 429 → 465 tests verts (+36 nouveaux,
2 hotfixes inclus), 0 régression, application durcie pour
la prod (headers stricts, rate limiting global 4 limiters,
IPN Paydunya signé SHA-512, Mercure JWT subscriber scopé
tenant, cache Redis KPIs invalidé au night audit, indexes
ciblés sur les requêtes analytics).

---

**B.1.1 — Headers HTTP de sécurité + Sentry backend**
- [x] `SecurityHeadersSubscriber` : pose à chaque réponse
  `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`,
  `Referrer-Policy: strict-origin-when-cross-origin`,
  `Permissions-Policy` minimaliste, CSP backend ultra-strict
  (`default-src 'none'` — l'API ne sert que du JSON, aucune
  ressource HTML/JS). `Strict-Transport-Security` posé
  uniquement en prod (HTTPS effectif).
- [x] SDK Sentry backend (`sentry/sentry-symfony`) câblé,
  DSN lu depuis `SENTRY_DSN` (no-op si vide → dev/test
  silencieux). Contexte tenant ajouté via `before_send`.
- [x] +3 tests : `SecurityHeadersTest` couvre la présence
  des headers attendus sur `/api/health` et l'absence de
  HSTS en env test.

---

**B.1.2.1 — Rate limiting global étendu**
- [x] 4 limiters configurés (`config/packages/rate_limiter.yaml`) :
  `api_read` (300/min, sliding window), `api_write`
  (60/min, sliding window), `webhooks` (100/min, sliding
  window), `otp_resend` (3 / 10min, fixed window).
  S'ajoute aux limiters préexistants `login` et
  `register` (Sprint 2).
- [x] `RateLimitSubscriber` (event `kernel.request`,
  priorité haute) sélectionne le limiter selon la route et
  la méthode HTTP. Clé = IP client. Renvoi 429
  `RATE_LIMITED` via `TooManyRequestsHttpException` (mappé
  par `ApiExceptionListener`).
- [x] `framework.trusted_proxies` configuré (lecture
  `REMOTE_ADDR` côté Heroku → vraie IP via `X-Forwarded-For`).
- [x] +5 tests : un test par limiter + un test trusted_proxies
  (lecture correcte de l'IP derrière proxy).

---

**B.1.2.2 — Vérification hash SHA-512 MasterKey IPN Paydunya**
- [x] `PaydunyaWebhookHandler` vérifie désormais le hash
  SHA-512 `hash('sha512', master_key)` envoyé dans le
  payload IPN. Rejet en 401 si non-conforme. La
  reconfirmation serveur via l'API Paydunya
  (`checkInvoice`) reste la source de vérité (jamais de
  confiance dans le seul payload — voir `security.md`).
- [x] Flag `PAYDUNYA_HASH_VERIFICATION_ENABLED`
  (`services.yaml` lit env) pour désactiver la vérification
  en dev/test (Paydunya ne signe pas en sandbox local).
  Activé par défaut, désactivé via `.env.test`.
- [x] +9 tests : hash valide / invalide / absent /
  désactivé via flag, sur les deux flux (SaaS invoice et
  hotel invoice) + cas reconfirmation API échoue malgré
  hash valide.

---

### 🔧 Hotfix — Garde-fou check-in sur réservation expirée (pendant 14-B)

**Contexte** : garde-fou métier manquant détecté entre
14-B.1.2.2 et 14-B.2 lors d'un smoke test. Une réservation
dont la `checkOut` était déjà passée (client qui ne s'est
jamais présenté, oubli de no-show) pouvait quand même être
check-in, créant des états incohérents (séjour rétroactif,
night audit corrompu).

**Cause racine** : `ReservationEngine::checkIn` validait
l'état (`status === CONFIRMED`) mais pas la fenêtre temporelle
de la réservation. Symétrique au cas no-show futur traité au
14-A.3 A.2 (`markNoShow` refuse `checkIn > today`) — l'autre
borne manquait.

**Impact pré-correction** : risque de corruption métier sur
les réservations oubliées. Cas rare en pratique mais bloquant
pour la cohérence du night audit.

**Correction** :
- [x] `ReservationEngine::checkIn` refuse désormais une
  réservation dont `checkOut < businessDate` (date de départ
  déjà passée). `BusinessRuleException` levée (422).
  `checkOut = today` reste valide (départ prévu le jour
  même).
- [x] +2 tests : `testCheckInOnExpiredReservationRefused`
  (checkOut J-1 → 422) + `testCheckInOnSameDayCheckoutAllowed`
  (checkOut = today → OK).

**Validation runtime** : run complet vert, aucune
régression sur les flux check-in nominaux.

**Leçon retenue** : voir leçons d'architecture — pattern des
garde-fous métier **symétriques**. Quand on ajoute une borne
temporelle d'un côté (`markNoShow` refuse futur), vérifier
systématiquement l'autre borne (`checkIn` doit refuser passé).

---

### 🔧 Hotfix — Garde-fous dates création/modification réservation (pendant 14-B)

**Contexte** : dates aberrantes acceptées à la
création/modification de réservation (ex : `checkOut` dans
le passé, `checkIn` très ancien). Détecté en revue manuelle
après le hotfix check-in.

**Cause racine** : `ReservationEngine::create` et `update`
validaient `checkOut > checkIn` (DTO) mais sans borne basse
absolue par rapport à `businessDate`. Le frontend
filtrait déjà les dates passées dans le picker — défense en
profondeur côté serveur manquante.

**Impact pré-correction** : un appel API direct (Postman,
client tiers, bug front) pouvait créer une réservation
historique invalide.

**Correction** :
- [x] `create` et `update` refusent désormais
  `checkOut < businessDate` (jamais de séjour entièrement
  dans le passé) et `checkIn < businessDate - 30 jours` si
  le flag `enforceCheckInWindow` est actif (fenêtre
  d'ouverture rétroactive limitée à 30 jours pour
  l'encodage des walk-ins oubliés). `BusinessRuleException`
  levée (422).
- [x] +5 tests : checkOut passé refusé, checkIn très
  ancien refusé avec flag actif, checkIn ancien accepté
  avec flag désactivé, modification d'une résa existante
  vers le passé refusée, walk-in J-1 (cas légitime)
  accepté.

**Validation runtime** : run complet vert, smoke test sur
les flux nominaux (création standard, modification dates
futures, walk-in J0) inchangé.

**Leçon retenue** : voir leçons d'architecture — la
validation DTO (`checkOut > checkIn`) couvre la cohérence
relative entre champs, mais ne remplace PAS les bornes
métier absolues (par rapport à `businessDate`). Toujours
ajouter les deux niveaux dans le service métier.

---

**B.2.1 — Mercure durcissement prod (JWT subscriber)**
- [x] `MercureSubscriberTokenService`
  (`Shared/Mercure/Service/`) génère un JWT subscriber
  scopé tenant : claim `mercure.subscribe` listant
  uniquement les topics `/hotel/{tenantId}/{event}` du
  tenant courant. Expiration 1h, signé avec
  `MERCURE_JWT_SECRET` (même secret que le hub — config
  partagée).
- [x] `MercureTokenController` expose
  `GET /api/mercure/token` (RBAC staff) : pose un cookie
  httpOnly `mercureAuthorization` (domaine et `secure`
  conditionnels) avec le JWT. EventSource côté front
  s'authentifie automatiquement via le cookie.
- [x] Binding `services.yaml` : `mercure.cookie.secure`
  et `mercure.cookie.domain` paramétrés différemment en
  dev (`secure: false`, domaine localhost) vs prod
  (`secure: true`, domaine `.getstayos.com`).
- [x] Refacto frontend `mercure.service.ts` :
  `ensureToken()` async appelé avant chaque `connect()`,
  `withCredentials: true` sur EventSource, timer de
  refresh à T-5min, `reset()` au logout (nettoie cookie
  et déconnecte la source).
- [x] CORS : `allow_credentials: true` ajouté sur la
  section `/api` de `nelmio_cors.yaml` (sinon le cookie
  Mercure n'est pas posé par le navigateur).
- [x] Le hub Mercure reste `anonymous=true` en dev — la
  bascule `anonymous=false` côté Caddy est prévue en
  14-C (en même temps que TLS Let's Encrypt et
  `publish_origins` restreint).
- [x] +7 tests : génération token (claims corrects, exp,
  scope tenant), endpoint expose cookie httpOnly,
  refresh remplace le token, RBAC bloque les non-staff,
  CORS allow_credentials présent, reset() nettoie l'état.

---

**B.2.2 — Cache Redis KPIs dashboard**
- [x] Pool `kpi.cache` dédié (`config/packages/cache.yaml`) :
  adapter Redis via `REDIS_URL` en dev/prod, adapter
  array en test (déterminisme + isolation).
- [x] `KpiService::dashboardForDateCached()` ajouté à
  côté de `dashboardForDate()` (qui reste NON caché).
  Clé `kpi_dashboard_{tenantId}_{Y-m-d}` (scope tenant
  obligatoire — sinon fuite cross-tenant). TTL adaptatif :
  300s pour la date du jour (rafraîchissement raisonnable
  pendant la journée), 86400s pour les dates passées
  (figées), bypass complet pour les dates futures (jamais
  pertinent à cacher).
- [x] **Invariant** : `DailyCloseService::buildSnapshot`
  appelle `dashboardForDate()` NON cachée — le snapshot
  night audit reste toujours frais, source de vérité
  comptable. La version cachée sert uniquement les vues
  dashboard temps réel.
- [x] Invalidation : `DailyCloseService::close()` et
  `reopen()` invalident la clé du jour clos via
  `invalidateDashboardCache($tenantId, $businessDate)`.
- [x] +5 tests : hit/miss sur date passée, hit/miss sur
  aujourd'hui, bypass sur date future, invalidation au
  close, scope tenant (un tenant A n'invalide pas B).

---

**Mini-fix 14-B.2.2 — Invalidation cache résiliente**
- [x] `invalidateDashboardCache` enrobé dans un `try/catch`
  silencieux (log warning, pas de re-throw). Un Redis
  indisponible ne doit pas faire échouer une clôture
  comptable déjà flushée — le cache se ré-hydrate
  naturellement au prochain accès ou au prochain
  redéploiement.

---

**B.2.3 — Indexes PostgreSQL ciblés**
- [x] Migration tenant `Version20260618000000AddAnalyticsIndexes` :
  `idx_reservation_status_checkin` (status, check_in),
  `idx_reservation_status_checkout` (status, check_out),
  `idx_payment_processed_at` (processed_at),
  `idx_invoice_status_issued_at` (status, issued_at).
  Cible les requêtes RevPAR, occupancy, CA journalier
  et les filtres dashboard.
- [x] `make test-security` continue de couvrir les
  headers HTTP via `SecurityHeadersTest` (déjà ajouté
  en B.1.1).
- [x] EXPLAIN ANALYZE validé sur les requêtes
  analytics : Index Scan utilisé quand le planner les
  considère ; Seq Scan reste optimal sur le faible
  volume dev (~12 réservations en fixtures), ce qui est
  normal — les indexes seront effectifs dès que les
  tables atteindront un volume significatif en prod.

**Livrable** : application durcie pour la prod côté
sécurité (headers HTTP stricts sur toutes les réponses
API, rate limiting global 4 limiters via Redis,
vérification SHA-512 des IPN Paydunya, Mercure JWT
subscriber scopé tenant côté front), côté performance
(cache Redis KPIs invalidé proprement au night audit,
indexes PostgreSQL ciblés sur les requêtes analytics),
et côté robustesse (2 garde-fous métier en hotfix
pendant le sprint, mini-fix résilience Redis).

**Reste pour 14-C** :
- [ ] Bascule hub Mercure `anonymous=false` côté Caddy
  + Config Vars (`MERCURE_SUBSCRIBER_JWT_KEY` /
  `SUBSCRIBER_JWT_KEY` selon convention retenue lors
  du déploiement)
- [ ] TLS Let's Encrypt sur le hub Mercure (HTTPS
  effectif via Caddy auto-cert)
- [ ] `publish_origins` restreint au domaine
  Heroku/backend (retirer le `*` dev)

---

### ⬜ Sprint 14-C — Déploiement
**Objectif** : `https://demo.getstayos.com` accessible,
stable, monitoré.

**Comptes à ouvrir**
- Heroku (backend + Mercure)
- Vercel (frontend)
- Cloudflare (DNS + CDN — déjà ouvert pour le domaine)
- Sentry (erreurs runtime)
- Papertrail (logs centralisés, addon Heroku)
- UptimeRobot (uptime monitoring + status page)

**Configuration**
- [ ] Domaine `getstayos.com` (acheté sur Cloudflare
  Registrar)
- [ ] DNS Cloudflare + wildcard `*.getstayos.com`
- [ ] Heroku Postgres Standard-0 + Heroku Data for Redis Mini
- [ ] Heroku app backend Standard-2X + Heroku app Mercure
  Hobby
- [ ] Vercel project frontend
- [ ] Audit `.env` côté prod — aucune valeur dev type
  `change_me_*`, `stayos_jwt_dev_secret` ne doit fuir
  (Chantier 6 recommandation)
- [ ] Sentry DSN configuré + smoke test (erreur volontaire
  pour valider le pipeline)
- [ ] Papertrail configuré + 4 alertes actives
- [ ] UptimeRobot configuré + status page live
- [ ] Smoke tests en prod (login, réservation, paiement
  Paydunya sandbox sur `demo.getstayos.com`)

**Documentation**
- [ ] `deploy.md` resynchronisé avec les commandes exactes
  Heroku Config Vars

**Livrable** : `https://demo.getstayos.com` opérationnel

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
| SaaS (suite) | S14-A.1 | ~1 semaine | Dettes critiques avant prod |
| SaaS (suite) | S14-A.2 | ~1 jour | UI manager politiques financières |
| SaaS (suite) | S14-A.3 | ~1 semaine | Cohérence et polish (5 sous-paquets) |
| Production | S14-B | ~1 semaine | Sécurité et performance (2 volets, +36 tests) |
| Production | S14-C | ~1-2 semaines | Déploiement |
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
- ✅ **Feature-gating via voter Symfony** — livré au Sprint
  14-A.1 Chantier 3 : `FeatureVoter` + attribut
  `#[IsGranted('FEATURE_<name>')]` sur 11 call sites
  (RateController 6, PromotionController 3, DashboardController 2)
  + `FeatureGuardTest` global avec 13 tests (5 strict 403
  PLAN_LIMIT + 6 lax 403/404 pour les endpoints `{id}` à cause
  du ParamConverter + 2 PRO pass). Le backlog estimait 4 call
  sites, l'audit a trouvé 11. Note : code HTTP réel = 403 (pas
  422 comme initialement supposé), `FeatureNotAvailableException`
  étend `HttpException(statusCode: 403)`.
- **Features annoncées sans contenu (priorité basse, décision UX
  produit)** : les fixtures déclarent `channel_manager`,
  `multi_property`, `api_access` dans le plan Pro. Ces features
  n'existent pas encore dans le code → promesse vide à terme.
  Trois options à arbitrer : (A) retirer ces clés des fixtures
  (honnête) ; (B) garder mais badger « Bientôt disponible » dans
  `feature-labels.ts` et `PricingView` (transparent) ; (C) laisser
  tel quel (risqué).

### Audit & traçabilité
- ✅ **`entityType` normalisé en PascalCase dans `audit_logs`** —
  livré au Sprint 14-A.3 A.1. 11 call sites migrés
  (`daily_close`, `staff_invitation`, `staff_user`, `tenant`).
  Les audit logs persistés avant la convention restent dans
  leur format (pas de migration de données — cosmétique
  uniquement, les méthodes `findByEntity`/`getHistory`
  acceptent indifféremment).
- ✅ **Bug `entityId='new'` corrigé** — livré au Sprint 14-A.3
  A.1. 4 services concernés (`ReservationEngine::create`,
  `DailyCloseService` close+reopen, `InvoiceService::refundPayment`,
  `FeeInvoiceService`). Remplacement de `'new'` par
  `(string) $entity->getId()` après le `persist()` —
  l'UuidV4Generator Doctrine assigne l'ID dès le persist,
  pas besoin d'attendre le flush.
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
  sur de gros volumes. ✅ Résolu au Sprint 14-A.3 B.2 :
  `vatXof` est désormais stocké dans `snapshot.invoices`
  via une SUM SQL des `taxXof` des factures issued. Le
  template Twig lit la valeur précalculée (fallback float
  conservé pour les snapshots pré-14-A.3 — invariant
  d'immutabilité respecté).
- ✅ **Cutoff hardcodé "5 h" dans NightAuditView** — résolu au
  Sprint 14-A.2 : exposé via `GET /api/night-audit/current`
  (`cutoffHour: int` ajouté aux 4 chemins de retour de
  `DailyCloseService::getCurrent`, calculé une seule fois en
  haut). Affiché côté front via `{{ current.cutoffHour }}`
  (type `NightAuditCurrent` étendu côté TS).
- ✅ **`lastEffectiveClose` indépendant de la pagination** —
  livré au Sprint 14-A.3 B.1. `NightAuditView` utilise
  désormais un `ref` mis à jour uniquement au `reload()`
  (qui force `page.value = 1`). `changePage` ne le touche
  plus, donc le bouton "Réouvrir" cible toujours la vraie
  dernière close même en page 2+.
- ✅ **Pattern feedback utilisateur uniformisé sur
  `pushUiToast`** — livré au Sprint 14-A.3 B.1. 4 vues
  refactorées (`InvoiceDetailView`, `TenantDetailView`,
  `StaffListView`, `TaskCard.vue`). Refs `successMsg/errorMsg`
  retirées, divs styled inline retirés, CSS dédié supprimé.
  La distinction "feedback transitoire" vs "état de page"
  (`paymentReturn` bandeau persistant) est préservée.
- ✅ **Garde-fou backend no-show futur** — livré au Sprint
  14-A.3 A.2. `ReservationEngine::markNoShow` refuse
  désormais `checkIn > businessDate` (comparaison strict,
  `checkIn = today` reste valide). Défense en profondeur
  côté serveur. Test
  `testNoShowOnFutureCheckInRefused` couvre le cas.
- ✅ **Contexte des logs `catch` uniformisé** — livré au
  Sprint 14-A.3 A.1. 11 logs préexistants enrichis avec
  `'class' => $e::class` (`AbonnementService::checkExpirations`,
  `PublishDailyAlertsHandler`, `PaydunyaWebhookHandler`,
  `PaydunyaGateway` ×2, `SubscriptionEmailService` ×2,
  `EmailService` ×2, `PaydunyaWebhookController`,
  `InvoiceController`, `ReservationEngine::checkOut`).
  `MercurePublisher` non touché (utilise déjà
  `get_class($e)`, équivalent fonctionnel).

### Mécanismes métier manquants
Manques fonctionnels structurels identifiés en cours de
livraison — souvent des concepts existants (enums, statuts)
mais sans logique de service ni d'UI. Justifient des sprints
dédiés plutôt qu'un fix ponctuel.

- ✅ **No-show implémenté** (livré Sprint 13quinquies-A) :
  `ReservationEngine::markNoShow` + politique tenant
  configurable (none / first_night / full, défaut
  first_night) avec surcharge cas par cas par le
  réceptionniste, facture frais distincte émise direct
  ISSUED, audit log enrichi avec `overridden: true` quand
  geste commercial. UI : bouton "Marquer no-show" sur fiche
  réservation visible si status ∈ {confirmed, pending} ET
  `checkIn <= today`, modale avec récap politique + total
  live + select override.
- ✅ **Refund implémenté** (livré Sprint 13quinquies-B) :
  `InvoiceService::refundPayment` avec Payment négatif +
  status PAID (pas de `PaymentStatus::REFUNDED` ajouté,
  décision documentée — le filtre `getCompletedPayments`
  ne le verrait pas), garde anti-over-refund, recalcul du
  statut Invoice (PAID → PARTIAL si refund partiel ou
  PAID → ISSUED si refund total, CANCELLED reste figé),
  audit log enrichi avec `amountRefunded` positif +
  `storedAsXof` négatif + transitions de statut. UI : bouton
  "Rembourser" sur fiche facture visible si `paidXof > 0`,
  modale avec validation client live + bandeau info "geste
  manuel agent client". Lignes refund visuellement
  distinctes dans la liste paiements.
- ✅ **Politique d'annulation implémentée** (livré Sprint
  13quinquies-A) : `ReservationFeeCalculator` (service pur)
  avec matrice 3 politiques × délais (flexible / moderate /
  strict, défaut flexible). `ReservationEngine::cancel`
  étendu avec calcul automatique + override commercial
  possible, retour `array {reservation, invoice, feeXof,
  feeQuote}`, endpoint `GET /cancellation-quote` dry-run pour
  pré-affichage UI. Facture distincte émise pour les frais.
  Tracking complet via audit log `feeOverridden: true` quand
  geste commercial.
- ✅ **UI manager pour configurer les politiques financières**
  — livré au Sprint 14-A.2 : onglet "Finances" dans
  `HotelConfigurationView` avec 3 selects pour `no_show_policy`,
  `cancellation_policy`, `business_day_cutoff_hour`. Endpoint
  `PATCH /api/tenant/settings` manager-only, validation enum,
  audit log avec diff before/after sur les seuls champs changés
  (pas d'entrée fantôme si no-op). 10 tests fonctionnels (RBAC,
  validation, isolation cross-tenant).

### Plateforme & onboarding
- ✅ **Transactionnaliser `OnboardingService::register/provision`** —
  livré au Sprint 14-A.1 Chantier 4 : `beginTransaction()`
  autour de chaque méthode, helper privé `dropSchemaSafely()`
  avec validation regex anti-injection, OTP positionné APRÈS
  `commit()` (panne Mailjet ne doit pas annuler l'inscription),
  3 tests fonctionnels qui forcent l'échec à différents points
  du flow et vérifient l'absence totale de résidus en BDD
  (tenant + schema). 0 orphan détecté par `cleanup-orphans`
  après l'exécution de la suite.
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
  récupérables via `psql -f /tmp/orphan_*.sql`. Note : le
  pattern `baselineOrphanSchemas` au setUp du
  `OnboardingTransactionalTest` (Sprint 14-A.1 Chantier 4)
  peut servir de référence pour les futurs tests qui créent
  des tenants — capture l'état initial et ne mesure QUE les
  nouveaux orphelins créés par le test.

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
- ✅ **Anti-spam toasts rafale 4+ corrigé** — livré au Sprint
  14-A.3 B.1. `notifications.store.ts` : ajout d'un champ
  `groupedCount?: number` sur `ToastEntry`. `pushToast` détecte
  désormais un toast déjà-groupé du même type dans la fenêtre
  et incrémente son compteur (4e+ toast fusionné dans le
  groupé existant au lieu de générer un nouveau toast).
- ✅ **Doublon `disconnect()` au logout retiré** — livré au
  Sprint 14-A.3 B.1. L'appel explicite à
  `useNotificationsStore().disconnect()` est retiré du
  `auth.store.logout()`. Le `watch(auth.isAuthenticated)` dans
  `App.vue` reste la voie canonique (couvre logout manuel +
  expiration JWT + redirection 401).
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
- ✅ **Bug Lexik rate limiter login corrigé** — livré au Sprint
  14-A.3 C.1. `LoginRateLimitListener` créé sur l'event
  `lexik_jwt_authentication.on_authentication_failure` ; détecte
  `TooManyLoginAttemptsAuthenticationException` (sur `$exception`
  direct OU `getPrevious()`) et renvoie 429 RATE_LIMITED.
  Pool dédié `cache.rate_limiter: filesystem` ajouté en env test
  (le cache `array` ne survit pas au reboot kernel).
  `testLoginRateLimitAfterFiveAttempts` réactivé avec
  `disableReboot()` + email unique + clear cache en setUp.
- **Compléter le type `TenantDetail`** (priorité basse, Sprint
  14-A.3 B.2) : `frontend/src/types/superadmin.ts` n'expose pas
  `timezone?: string`, alors que `TenantDetailView::saveEdit`
  l'envoie au backend. Contourné actuellement par un cast
  `(tenant.value as unknown as { timezone?: string }).timezone`.
  Ajouter le champ au type officiel à la prochaine touche du
  module SuperAdmin.
- ✅ **Scripts npm racine cassés (frontend/)** — livré au
  Sprint 14-A.1 Chantier 2 : `frontend/tsconfig.json` créé
  (extends `@vue/tsconfig/tsconfig.dom.json`), `eslint.config.js`
  flat config eslint 9 créé, cible Makefile `npm-type-check`
  ajoutée. `make npm-build` + `make npm-lint` + `make npm-type-check`
  tous verts. 5 bugs typés TypeScript révélés et corrigés au
  passage (à valider robustesse en polish 14-A.3).
- **Suite complète à chaque clôture de sprint** : les régressions
  `InvoiceServiceTest` (Sprint 7) et le 404 fonctionnel (fixtures
  test jamais chargées) ont été révélés tard parce que `make test`
  complet n'avait pas tourné régulièrement. Établir un réflexe de
  fin de sprint : suite complète verte AVANT le commit de clôture.
  Idéalement, automatiser via un pre-commit hook ou une étape CI au
  Sprint 14.
- ✅ **Auditer les `catch (\Throwable)` silencieux** — livré
  au Sprint 14-A.1 Chantier 5 : 31 occurrences auditées, 4
  TYPE 1 (silencieux) corrigés en TYPE 2 (loggé)
  — `HealthController` DB + Redis probes,
  `SubscriptionController::computeUsage` rooms + staff_users.
  2 TYPE 2 justifiés (`CheckSubscriptionsHandler`,
  `AbonnementService::checkExpirations`), 25 TYPE 3 (bien gérés)
  RAS. Document d'audit : `backend/docs/catch-audit-2026-14.md`.
- **Test reflection / PHPStan custom anti-régression catch
  silencieux** (priorité basse, recommandation Sprint 14-A.1
  Chantier 5) : scanner `backend/src/` à la recherche de
  `catch (...) { ... }` dont le corps ne contient ni
  `$logger->` ni `throw`. À planifier 14-A.3 polish ou 14-B.
  Bloquerait toute régression dans les futurs PRs.
- **Tests de retour Paydunya non couverts (Sprint 12)** : les pages
  `PaymentReturnView` et `PaymentCancelView`, ainsi que le polling
  `pending`, ne sont pas couverts par des tests automatisés. Le
  paiement Paydunya bout en bout exige Paydunya sandbox + ngrok,
  inadapté à la CI. Envisager une commande de test
  `stayos:test:paydunya-ipn` qui mocke `PaydunyaService` et envoie
  un IPN simulé directement sur le webhook — utile pour la CI et
  pour valider la chaîne SaaS en dev sans Paydunya réel.
- ✅ **Audit des paramètres Symfony hardcodés** — livré au
  Sprint 14-A.1 Chantier 6 : 25 fichiers YAML inventoriés,
  19 références `%env(VAR)%` croisées avec `backend/.env` —
  toutes documentées. 0 migration nécessaire (le fix Sprint 12
  avait fait le ménage principal). Document d'audit :
  `backend/docs/yaml-audit-2026-14.md`.
- **Test reflection yaml ↔ .env** (priorité basse,
  recommandation Sprint 14-A.1 Chantier 6) : scanner
  `config/**/*.yaml` pour les `%env(VAR)%` et vérifier qu'une
  entrée `VAR=...` existe dans `backend/.env`. Éviterait une
  régression à la Sprint 12 (URL hardcodée pendant un sprint
  entier). À planifier 14-A.3 ou 14-B.
- ✅ **Tests `@group integration` silencieusement skippés** —
  livré au Sprint 14-A.1 Chantier 1 (option A) : 44 annotations
  retirées + directive `<group>integration</group>` retirée du
  bloc `<exclude>` de `phpunit.xml.dist`. `make test` est passé
  de 193 → 401 tests (+208 ex-integration intégrés à la suite
  par défaut). Cible Makefile `test-integration` conservée pour
  un usage V2 potentiel. Régression cachée révélée et corrigée
  au passage : `ReservationPromoTest` avec des dates non
  actualisées.
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
- ✅ **Mode strict TypeScript pour les imports de services** —
  livré au Sprint 14-A.1 Chantier 2 : `tsconfig.json` racine
  avec `strict: true`, `noImplicitAny: true`, alias `@/*` →
  `./src/*` aligné avec Vite, `vue-tsc --noEmit` accessible
  via `make npm-type-check`. Le run initial a effectivement
  révélé 2 appels `roomService.updateType` (au lieu de
  `roomTypeService.update`) qui auraient cassé en prod —
  corrigés au passage. 6 warnings ESLint résiduels à traiter
  en polish 14-A.3.
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
- **Auditer `GET /api/rooms/types`** (priorité basse, Sprint
  14-A.3 C.1) — legacy lecture conservé au C.1. Le PUT
  homonyme `/api/rooms/types/{typeId}` a été supprimé,
  la route canonique étant `/api/room-types/{id}`. Vérifier
  si le GET est encore utilisé par le frontend, supprimer
  sinon. À traiter à la prochaine touche du module Room.
- **Auditer les autres helpers de test `now + Xh` formaté
  en `Y-m-d`** (priorité basse, Sprint 14-A.3 C.1) : la
  colonne `reservations.check_in` est de type DATE (perd
  l'heure), donc tout helper qui pose une `check_in` à
  partir d'un offset en heures est sensible à l'heure
  d'exécution. Pattern corrigé sur
  `CancellationWithFeesTest::seedReservation`. Auditer
  `NoShowTest`, `ReservationTest`,
  `LockedDayPreventsModificationTest`. Si trouvé, appliquer
  le même pattern (`today + N jours civils`).

#### Deprecations PHP/Symfony (priorité basse)
- ✅ **`StaffUser::eraseCredentials()` annotée `#[\Deprecated]`** —
  livré au Sprint 14-A.3 C.1 (attribut natif PHP 8.4 +
  PHPDoc `@deprecated`, since 7.3).
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
- ✅ **JWT subscriber par session staff** — livré au Sprint
  14-B.2.1. `MercureSubscriberTokenService` génère un JWT
  scopé tenant (claim `mercure.subscribe` =
  `/hotel/{tenantId}/{event}`, exp 1h), exposé via
  `GET /api/mercure/token` (cookie httpOnly
  `mercureAuthorization`). Frontend `mercure.service.ts`
  refactoré (`ensureToken` async, `withCredentials: true`,
  refresh timer, `reset()` au logout). CORS
  `allow_credentials: true` sur `/api`.
- **Reste à faire au Sprint 14-C** :
  - Bascule hub Caddy `anonymous=false` + Config Vars
    (`MERCURE_SUBSCRIBER_JWT_KEY` / `SUBSCRIBER_JWT_KEY`
    selon la convention retenue).
  - TLS réel (Caddy auto-cert Let's Encrypt en HTTPS,
    comportement par défaut dunglas/mercure si on enlève le
    `SERVER_NAME: ':80'`).
  - Retirer `publish_origins *` et restreindre au domaine
    Heroku/backend.
  - `cors_origins` restreint au domaine de prod
    (`https://*.getstayos.com`).

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
- **Payment négatif + status PAID pour matérialiser une
  sortie de caisse sans nouveau status** (Sprint 13quinquies-B) :
  pour les opérations comptables négatives (refund client,
  sortie de caisse, ajustement), créer une ligne dans la même
  table que les entrées mais avec amount négatif et le même
  status `PAID` (effectivement réalisé). Plus simple et plus
  robuste que d'ajouter un status `REFUNDED` dédié, qui forcerait
  à modifier tous les filtres en aval (`getCompletedPayments`,
  `getPaidXof`) pour inclure le nouveau status. Le calcul de
  balance via `bcadd` somme naturellement les négatifs. Pattern
  à reproduire pour : remboursements clients, sorties de caisse
  diverses, ajustements comptables, avoirs. Discrimine via
  `amount < 0` côté UI pour affichage différencié (couleur
  rouge, badge "Remboursement", etc.). La sémantique
  « registre comptable double » est préservée naturellement.
- **Composant modal doit être robuste à son pattern
  d'instanciation** (Sprint 13quinquies, fix robustesse) :
  un composant Vue qui pilote son chargement via
  `watch(() => props.isOpen)` casse quand le composant est
  monté via `v-if` avec `isOpen=true` à la création
  (`ReservationsView` vs `ReservationDetailView`). Le `watch`
  ne capture pas la valeur initiale, il attend une transition
  `false → true`. Solution : combiner `onMounted` (capture la
  valeur initiale) + `watch` (capture les changements
  ultérieurs). Pattern à appliquer à tous les composants
  modales / popovers / drawers qui font du fetch piloté par
  une prop `isOpen` / `visible` / `active`. Règle simple : si
  le composant fait du fetch au "moment d'ouverture", **les
  deux hooks doivent l'invoquer**.
- **DTOs validation à double couche (Symfony Validator +
  service métier)** (Sprint 13quinquies-B) : `RefundDTO` a
  ses contraintes Symfony Validator (`NotBlank`, `Type(numeric)`,
  `GreaterThan(0)`, `Length(min: 5)`, `Choice`) qui filtrent
  les données mal formées en amont. Mais le service
  `InvoiceService::refundPayment` ajoute en plus une garde
  métier (`bccomp($amountXof, $alreadyPaid, 2) > 0` pour
  l'anti-over-refund) qui ne peut PAS être exprimée
  déclarativement (elle dépend de l'état runtime de
  l'invoice). Pattern à appliquer : DTOs pour la validation
  de **forme** (types, longueurs, formats) ; service métier
  pour la validation de **cohérence runtime** (relations
  entre champs, état BDD, contraintes business). Ne pas
  mélanger : le DTO ne doit pas charger l'invoice depuis la
  BDD pour valider, et le service ne doit pas dupliquer les
  contraintes de forme.
- **Pattern dry-run quote avant action mutante**
  (Sprint 13quinquies-A, généralisé) : pour toute action
  coûteuse, irréversible ou avec impact financier, exposer
  un endpoint `GET /xxx-quote` qui calcule les conséquences
  SANS rien modifier en BDD. L'UI fetch le quote au mount de
  la modale de confirmation, l'utilisateur voit les
  conséquences (montant à facturer, frais, durée, etc.),
  puis confirme via `POST /xxx` qui réalise vraiment l'action.
  Pattern cohérent avec `GET /night-audit/current` (Sprint
  13quater) vs `POST /night-audit/close`. À reproduire pour :
  upgrade de plan (proration), suppression de chambre (cascade
  sur résa), changement de tarif (impact sur résas futures),
  bulk operations (preview avant exécution). Améliore la
  confiance utilisateur et évite les surprises post-action.
- **`#[IsGranted('FEATURE_<name>')]` au lieu de
  `('FEATURE', '<name>')`** (Sprint 14-A.1, Chantier 3) :
  Symfony interprète le second argument d'`IsGranted`
  comme un nom d'argument du contrôleur (sujet à passer
  au voter), pas comme une chaîne littérale. Pour passer
  une chaîne littérale il faudrait `new Expression("'...'")`
  qui requiert `symfony/expression-language` (non
  installé). Solution adoptée : encoder la feature dans
  le nom d'attribut (`FEATURE_<name>`) avec un voter qui
  utilise `str_starts_with` + `substr` pour extraire le
  nom. Évite la dépendance, reste tout aussi déclaratif.
  Pattern à reproduire pour tout nouveau voter qui aurait
  besoin de passer un argument littéral à
  `voteOnAttribute`.
- **ParamConverter s'exécute AVANT `IsGranted` method-level**
  (Sprint 14-A.1, Chantier 3) : pour les méthodes
  controller avec `{id}` dans l'URL, Symfony résout
  l'entité via le ParamConverter dans
  `onKernelControllerArguments` (priorité haute), AVANT
  que l'attribut `IsGranted` method-level ne soit évalué.
  Conséquence : si l'entité n'existe pas dans le schema
  tenant, un 404 NOT_FOUND tombe avant que le voter ne
  puisse refuser pour une autre raison (PLAN_LIMIT,
  ACCESS_DENIED, etc.). Les deux statuts bloquent
  effectivement l'accès, mais il faut en tenir compte
  dans les tests : les helpers `assertFeatureBlocked` et
  `assertEndpointBlocked` du `FeatureGuardTest` font
  cette distinction (strict 403 pour POST sans `{id}`,
  lax 403 ou 404 pour PUT/DELETE avec `{id}`).
- **Voter qui THROW au lieu de RETURN false** (Sprint
  14-A.1, Chantier 3) : pattern orthodoxe Symfony =
  retourner `bool` → Symfony lève
  `AccessDeniedException` générique avec message
  standard. Si le voter doit lever une exception métier
  spécifique (code HTTP custom, message custom, code
  d'erreur API custom), il PEUT throw directement
  l'exception. Le `HttpException` remonte normalement au
  kernel listener. Pattern à reproduire pour tout voter
  qui doit préserver une UX spécifique. Documenter dans
  le DocBlock du voter.
- **`baselineOrphanSchemas` au setUp des tests** (Sprint
  14-A.1, Chantier 4) : quand un test mesure l'absence
  de résidus en BDD partagée, capturer au setUp l'état
  initial pour ne mesurer QUE les NOUVEAUX résidus créés
  par le test (via `array_diff(current, baseline)`).
  Évite que le test échoue à cause de résidus laissés
  par d'autres tests qui ne nettoient pas (faux positif).
  Pattern à reproduire pour tout test qui vérifie une
  propriété globale en BDD.
- **DROP SCHEMA défensif après rollback** (Sprint 14-A.1,
  Chantier 4) : `CREATE SCHEMA` PostgreSQL est en
  théorie transactionnel mais la pratique a démontré au
  Sprint 13ter (24 schemas orphelins observés) qu'il y
  a au moins un cas où le rollback ne suffit pas
  (probablement migrations intermédiaires qui font des
  COMMIT implicites). Défense en profondeur : transaction
  Doctrine + DROP SCHEMA explicite dans le catch, avec
  validation regex anti-injection avant l'exécution du
  DROP. Pattern à reproduire pour tout endroit qui fait
  du DDL — ne JAMAIS confier au rollback ORM seul.
- **Operation secondaire APRÈS `commit()`** (Sprint
  14-A.1, Chantier 4) : les opérations non-bloquantes
  (envoi d'email, notification, hook secondaire) doivent
  être placées APRÈS le `commit()` de la transaction
  principale. Sinon une panne du service externe (Mailjet
  down, Mercure unreachable) annule la transaction
  principale (création tenant, enregistrement
  réservation), ce qui est absurde. Coût : si le hook
  secondaire rate, l'utilisateur ne reçoit pas l'email
  mais l'opération principale a réussi — il pourra
  demander un renvoi via l'UI. Pattern à reproduire pour
  tout flow où l'opération principale ne dépend pas
  sémantiquement du hook secondaire.
- **Adapter le test au comportement réel, pas au backlog**
  (Sprint 14-A.1, Chantier 3) : le backlog mentionnait
  "422 PLAN_LIMIT" pour les refus de feature, mais le
  code réel `FeatureNotAvailableException` retourne 403.
  Important de lire le code AVANT d'écrire le test —
  sinon on écrit des assertions qui s'attendent à 422 et
  on fait planter en production. Idem pour le volume :
  le backlog estimait 4 call sites, en réalité 11.
  Réflexe à appliquer : ne jamais se fier aveuglément
  aux chiffres ou statuts mentionnés dans le backlog,
  toujours grep le code source AVANT de cadrer un
  chantier qui dépend de ces chiffres.
- **`tsc --noEmit` en pré-commit pour les projets Vue/TS**
  (Sprint 14-A.1, Chantier 2) : `vite build` ne fait pas
  de type-check strict par défaut. Le script
  `npm run build` du projet utilise `vue-tsc && vite build`
  mais nécessite un `tsconfig.json` à la racine pour
  fonctionner. Si le `tsconfig.json` manque, `vue-tsc`
  plante silencieusement (en remontant l'erreur, mais
  sans bloquer le développement quotidien). Pattern à
  appliquer : intégrer `npm run type-check` (ou son
  équivalent) dans la CI dès le début du projet Vue/TS,
  pas en fin de cycle comme dette.
- **`mixed` au lieu de `?int`/`?string` pour les DTO validés
  par Symfony Validator** (Sprint 14-A.2) : si un champ DTO
  est typé `?int` et que le payload envoie une string `"25"`,
  l'assignation `$dto->x = "25"` throw un `TypeError` PHP
  natif AVANT que `Assert\Type('integer')` ne puisse rejeter
  proprement avec un message FR. Avec `mixed`, l'assignation
  passe et le validateur retourne un 422 VALIDATION_ERROR
  explicite. À utiliser pour tout champ DTO où la coercition
  PHP automatique masquerait une erreur de typage côté client.
  Coût : un cast explicite est requis après validation (mais
  c'est sûr puisque `Assert\Type` a déjà vérifié).
- **`array_key_exists` vs `isset` pour les PATCH partiels**
  (Sprint 14-A.2) : `isset` retourne `false` pour une clé
  explicitement à `null` dans un array, ce qui empêche de
  distinguer "champ absent du payload" de "champ explicitement
  nullifié par le client". Pour un PATCH REST où l'absence et
  le null peuvent avoir des sémantiques différentes
  (`{champ: null}` = "supprimer" vs `{}` = "ne pas toucher"),
  `array_key_exists` est obligatoire. Pattern à appliquer
  systématiquement aux endpoints PATCH.
- **RBAC en vérif manuelle plutôt qu'attribut `IsGranted`
  quand le message d'erreur compte** (Sprint 14-A.2,
  généralisation Sprint 14-A.1 Chantier 3) : pattern Symfony
  orthodoxe = `#[IsGranted('ROLE_X')]` → si refusé, Symfony
  retourne un `AccessDeniedException` générique avec message
  standard. Si le code d'erreur API ou le message UX doivent
  être customs (cas FeatureVoter, cas TenantSettingsController),
  préférer une vérif manuelle dans le controller :
  `if (!$this->isGranted('ROLE_X')) { return $this->jsonError(...) }`.
  À documenter dans le DocBlock du controller pour signaler
  l'intention.
- **Skip audit log si aucun changement effectif** (Sprint
  14-A.2, généralisation pattern Sprint 13bis-B) : un PATCH
  qui envoie les MÊMES valeurs que les valeurs courantes ne
  doit PAS créer d'entrée d'audit log. Sinon on pollue la
  timeline avec des updates fantômes, et on perd la capacité
  de filtrer "qui a vraiment modifié quoi". Pattern : capturer
  le `before` AVANT l'apply, calculer le `diff` après apply,
  et ne logger que si `$diff !== []`. Pattern à appliquer
  systématiquement aux endpoints PATCH idempotents.
- **`entityId` disponible dès `persist()` avec UuidV4Generator
  custom Doctrine** (Sprint 14-A.3 A.1) : avec un
  `#[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]`,
  l'UUID est généré côté PHP au moment du `persist()` —
  pas besoin d'attendre le `flush()` pour le récupérer. Le
  bug `entityId='new'` était dû à un anti-pattern où le code
  passait `'new'` littéral parce qu'il croyait l'ID
  indisponible avant flush. Pattern à appliquer : pour tout
  appel à `$auditService->log(entityId: ...)` sur une
  entité tout juste créée, passer `(string) $entity->getId()`
  après `persist()`. Pour les entités à ID séquence DB (non
  utilisées en StayOS), il faudrait flush avant l'audit log
  + re-flush ensuite (cf. le sous-cas documenté dans le
  prompt A.1).
- **Convention `entityType` en PascalCase, alignée sur les
  noms de classes Doctrine** (Sprint 14-A.3 A.1) : éviter
  les mélanges snake_case / PascalCase qui rendent les
  requêtes audit imprévisibles. Les audit logs persistés
  avant la convention restent dans leur format pour ne pas
  exiger de migration de données — les nouveaux logs
  respectent strictement PascalCase. Pattern : `entityType
  => 'StaffUser'` plutôt que `'staff_user'`. Si on doit
  écrire un `findByEntity()`, supporter les deux formats
  pendant la transition.
- **Distinction sémantique entre états tenants et états
  subscriptions** (Sprint 14-A.3 A.2) : `TenantStatus::CHURNED`
  (tenant définitivement résilié, irrécupérable) vs
  `Subscription.status = 'cancelled'` (transaction
  subscription annulée — un trial peut être annulé sans
  faire passer le tenant en CHURNED). Ne PAS aligner les
  deux enums — leur sémantique est volontairement
  distincte. Le nommage des champs qui les exposent (DTO,
  UI) doit refléter ce qu'il compte : `churnedTenantsCount`
  pour les tenants, `cancelledSubscriptionsCount` pour les
  subscriptions.
- **Garde-fou serveur en complément du filtrage frontend**
  (Sprint 14-A.3 A.2) : un check métier doit toujours
  exister côté backend, même si l'UI filtre déjà la
  possibilité de le déclencher. Exemple : `markNoShow`
  refuse `checkIn > today` côté serveur, alors que
  `canMarkNoShow` filtre déjà côté frontend. Raison :
  protection contre les requêtes API directes (curl,
  Postman, exploits). Pattern à appliquer à toutes les
  opérations métier sensibles.
- **Helper de test temporel avec colonnes DATE : raisonner
  en jours civils, pas en heures** (Sprint 14-A.3 C.1) :
  si la colonne BDD est `DATE` (date seule), formater
  `now + Xh` → `Y-m-d` perd la composante horaire et le
  re-parse Doctrine retombe à minuit. Le test devient
  alors dépendant de l'heure d'exécution. Solution : poser
  les dates en jours civils (`today + N jours`) et calculer
  `N = ceil(hoursOffset / 24)` pour atteindre la bande
  visée. Pattern à appliquer pour tout helper de test qui
  pose une `check_in`, `check_out`, ou autre colonne DATE.
- **Pool de cache dédié pour les rate-limiters en env test**
  (Sprint 14-A.3 C.1) : Symfony `login_throttling` utilise
  un cache pool pour ses compteurs. En env test, le cache
  par défaut est `array` (in-memory), réinitialisé entre
  chaque requête du `KernelBrowser` (reboot du kernel). Le
  rate limiter ne peut donc jamais déclencher. Solution :
  configurer un pool dédié `cache.rate_limiter: filesystem`
  en env test, ET utiliser `$client->disableReboot()` dans
  les tests qui s'appuient sur l'état partagé entre
  requêtes. ET clear le pool en setUp pour isoler les
  tests entre eux.
- **Listener Lexik pour mapper exceptions Symfony en codes
  HTTP custom** (Sprint 14-A.3 C.1) : `Lexik\AuthenticationFailureHandler`
  mappe par défaut toute exception Symfony en 401 générique.
  Pour préserver un code HTTP métier (429 RATE_LIMITED, 423
  LOCKED, etc.), utiliser un listener sur l'event
  `lexik_jwt_authentication.on_authentication_failure` qui
  détecte l'exception spécifique (sur `$exception` direct
  OU sur `$exception->getPrevious()` — Lexik peut
  encapsuler) et override la réponse via
  `$event->setResponse()`. Pattern à reproduire pour tout
  cas où le code d'erreur métier doit transparaître.
- **Override de paramètre Symfony dans services.yaml plutôt
  que modif de security.yaml** (Sprint 14-A.3 C.1) : quand
  un bundle Symfony évolue son API interne (signature de
  constructeur changée d'un type primitif vers un Enum
  par exemple), préférer un override du paramètre dans
  `services.yaml` plutôt que de modifier la config du
  bundle (security.yaml). Avantages : (a) la config du
  bundle reste idiomatic, (b) la déviation est localisée
  et documentée explicitement dans services.yaml, (c)
  utilisation de `!php/enum` Symfony 7.x au lieu de string
  casts hackeux. Exemple appliqué :
  `security.authentication.hide_user_not_found: !php/enum
  Symfony\…\ExposeSecurityLevel::None`.
