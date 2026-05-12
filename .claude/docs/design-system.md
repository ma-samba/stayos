# Design System — Référence complète

Fichier de référence HTML : `pms-design-system.html` (à la racine du projet)
Toujours consulter ce fichier pour voir le rendu visuel des composants.

---

## Identité visuelle

**Nom** : StayOS PMS
**Typographie** : DM Sans (300/400/500) + DM Mono
**Esthétique** : Raffinée, professionnelle, chaleureux (tons sable/or/encre)
**Pas de** : Inter, Roboto, gradients violets, ombres génériques

---

## Tokens CSS — Variables à utiliser PARTOUT

```css
/* À importer depuis src/shared/styles/tokens.css */
:root {
  /* ── Couleurs principales ── */
  --pms-ink:        #1A1714;   /* Texte principal, fond sidebar */
  --pms-ink-2:      #3D3830;   /* Texte secondaire */
  --pms-ink-3:      #6B6459;   /* Labels, texte muted */
  --pms-sand:       #F5F0E8;   /* Fond de page, stat cards */
  --pms-sand-2:     #EDE7D9;   /* Fond secondaire, tabs */

  /* ── Accent ── */
  --pms-gold:       #C4922A;   /* Accent premium, bouton Upgrade */
  --pms-gold-light: #F5E6C8;
  --pms-gold-dark:  #8A6319;

  /* ── Sémantiques ── */
  --pms-teal:       #1D6E6E;   /* Check-in, actions positives, badges */
  --pms-teal-light: #D4EDED;
  --pms-teal-dark:  #0D4444;
  --pms-green:      #2E7D4F;   /* Chambre disponible, succès */
  --pms-green-light:#D4EDE0;
  --pms-red:        #B83232;   /* Chambre occupée, erreur, danger */
  --pms-red-light:  #F5DADA;
  --pms-blue:       #2B5BA8;   /* Réservation confirmée, info */
  --pms-blue-light: #D4E2F5;

  /* ── Bordures ── */
  --pms-border:     rgba(26,23,20,0.10);  /* Bordure par défaut */
  --pms-border-2:   rgba(26,23,20,0.18); /* Bordure accentuée */

  /* ── Typographie ── */
  --font: 'DM Sans', sans-serif;
  --mono: 'DM Mono', monospace;

  /* ── Border radius ── */
  --radius-sm: 6px;    /* Badges, petits éléments */
  --radius-md: 10px;   /* Inputs, boutons, cards internes */
  --radius-lg: 16px;   /* Cards principales */
  --radius-xl: 24px;   /* Modales */
}
```

### Règle d'utilisation des couleurs

| Contexte | Couleur |
|---|---|
| Chambre disponible | `--pms-green` / `--pms-green-light` |
| Chambre occupée | `--pms-red` / `--pms-red-light` |
| Ménage en cours | `--pms-gold` / `--pms-gold-light` |
| Maintenance | `--pms-blue` / `--pms-blue-light` |
| Check-in / En séjour | `--pms-teal` / `--pms-teal-light` |
| Réservation confirmée | `--pms-blue` / `--pms-blue-light` |
| En attente | `--pms-gold` / `--pms-gold-light` |
| Annulée / Erreur | `--pms-red` / `--pms-red-light` |
| Succès / Terminée | `--pms-green` / `--pms-green-light` |
| Plan Starter | `--pms-ink` (fond sombre) |
| Plan Pro | `--pms-teal` |
| Plan Enterprise | `--pms-gold` |
| Wave | `#1DAB44` |
| Orange Money | `#FF6600` |

---

## Typographie

```css
/* Display — grands titres marketing, onboarding */
font-size: 28px; font-weight: 300; letter-spacing: -0.02em;

/* H1 — titre de page */
font-size: 22px; font-weight: 500;

/* H2 — titre de section */
font-size: 18px; font-weight: 500;

/* H3 — titre de carte */
font-size: 15px; font-weight: 500;

/* Body — texte courant */
font-size: 14px; font-weight: 400; line-height: 1.5; color: var(--pms-ink-2);

/* Small — dates, métadonnées */
font-size: 12px; color: var(--pms-ink-3);

/* Label — étiquettes de champs (UPPERCASE) */
font-size: 11px; font-weight: 500; letter-spacing: 0.06em; text-transform: uppercase;

/* Mono — références, numéros (RES-04821) */
font-family: var(--mono); font-size: 12px; color: var(--pms-teal);
```

**Règles** :
- Jamais de `font-weight: 600` ou `700` — trop lourd
- Sentence case partout (pas de Title Case ni ALL CAPS sauf labels)
- Références et numéros → toujours `font-family: var(--mono)`

---

## Boutons

```vue
<!-- Primaire — action principale -->
<button class="btn btn-primary">
  <i class="ti ti-plus"></i> Nouvelle réservation
</button>

<!-- Secondaire — action alternative -->
<button class="btn btn-secondary">
  <i class="ti ti-edit"></i> Modifier
</button>

<!-- Gold — upgrade / actions premium -->
<button class="btn btn-gold">
  <i class="ti ti-star"></i> Passer en Pro
</button>

<!-- Ghost — annulation, actions discrètes -->
<button class="btn btn-ghost">Annuler</button>

<!-- Danger — suppression -->
<button class="btn btn-danger">
  <i class="ti ti-trash"></i> Supprimer
</button>

<!-- Tailles : btn-sm (30px), défaut (38px), btn-lg (46px) -->
<!-- Icône seule : ajouter btn-icon (width = height) -->
```

```css
.btn {
  display: inline-flex; align-items: center; gap: 7px;
  height: 38px; padding: 0 18px; border-radius: var(--radius-md);
  font-family: var(--font); font-size: 13px; font-weight: 500;
  cursor: pointer; border: none; transition: all 0.15s;
}
.btn-primary  { background: var(--pms-ink); color: #fff; }
.btn-secondary { background: transparent; color: var(--pms-ink); border: 0.5px solid var(--pms-border-2); }
.btn-gold     { background: var(--pms-gold); color: #fff; }
.btn-ghost    { background: transparent; color: var(--pms-ink-3); border: none; }
.btn-danger   { background: var(--pms-red-light); color: var(--pms-red); border: 0.5px solid rgba(184,50,50,0.2); }
```

---

## Badges & Statuts

Toujours avec un point coloré (`badge-dot`) pour les statuts dynamiques.

```vue
<!-- Statuts chambres -->
<span class="badge badge-available"><span class="badge-dot"></span>Disponible</span>
<span class="badge badge-occupied"><span class="badge-dot"></span>Occupée</span>
<span class="badge badge-cleaning"><span class="badge-dot"></span>Ménage en cours</span>
<span class="badge badge-maintenance"><span class="badge-dot"></span>Maintenance</span>
<span class="badge badge-checkin"><span class="badge-dot"></span>Check-in aujourd'hui</span>

<!-- Statuts réservations -->
<span class="badge" style="background:var(--pms-blue-light);color:var(--pms-blue);">
  <span class="badge-dot" style="background:var(--pms-blue);"></span>Confirmée
</span>

<!-- Plans -->
<span class="badge" style="background:var(--pms-ink);color:#fff;">Starter</span>
<span class="badge" style="background:var(--pms-teal);color:#fff;">Pro</span>
<span class="badge" style="background:var(--pms-gold);color:#fff;">Enterprise</span>

<!-- Paiements -->
<span class="badge" style="background:#1DAB44;color:#fff;">
  <i class="ti ti-device-mobile"></i> Wave
</span>
<span class="badge" style="background:#FF6600;color:#fff;">
  <i class="ti ti-device-mobile"></i> Orange Money
</span>
```

```css
.badge {
  display: inline-flex; align-items: center; gap: 5px;
  padding: 3px 10px; border-radius: 100px;
  font-size: 11px; font-weight: 500;
}
.badge-dot { width: 5px; height: 5px; border-radius: 50%; }

.badge-available   { background: var(--pms-green-light); color: var(--pms-green); }
.badge-occupied    { background: var(--pms-red-light);   color: var(--pms-red); }
.badge-cleaning    { background: var(--pms-gold-light);  color: var(--pms-gold-dark); }
.badge-maintenance { background: var(--pms-blue-light);  color: var(--pms-blue); }
.badge-checkin     { background: var(--pms-teal-light);  color: var(--pms-teal-dark); }
```

---

## Formulaires

```vue
<!-- Input standard -->
<div class="input-wrap">
  <span class="input-label">Nom du client</span>
  <input class="input" type="text" placeholder="Amadou Diallo" />
</div>

<!-- Input avec icône -->
<div class="input-wrap">
  <span class="input-label">Email</span>
  <div class="input-icon-wrap">
    <i class="ti ti-mail input-icon"></i>
    <input class="input" type="email" placeholder="client@email.com" />
  </div>
  <span class="input-hint">Utilisé pour l'envoi de la facture</span>
</div>

<!-- Input en erreur -->
<input class="input input-error" />
<span class="input-hint error">
  <i class="ti ti-alert-circle"></i> Format invalide
</span>

<!-- Select -->
<select class="select">
  <option>Standard</option>
  <option>Deluxe</option>
</select>

<!-- Toggle -->
<button class="toggle on" @click="toggle()"></button>

<!-- Checkbox -->
<div class="check-wrap" @click="check.classList.toggle('on')">
  <div class="check on"><i class="ti ti-check"></i></div>
  <span class="check-label">J'accepte les conditions</span>
</div>
```

```css
.input {
  height: 38px; padding: 0 14px;
  border: 0.5px solid var(--pms-border-2); border-radius: var(--radius-md);
  font-family: var(--font); font-size: 13px; background: #fff;
}
.input:focus { border-color: var(--pms-ink); box-shadow: 0 0 0 3px rgba(26,23,20,0.06); }
.input-error  { border-color: var(--pms-red); }
.input-label  { font-size: 11px; font-weight: 500; color: var(--pms-ink-3); letter-spacing: 0.04em; }
.input-hint   { font-size: 11px; color: var(--pms-ink-3); }
.input-hint.error { color: var(--pms-red); }

.toggle { width: 40px; height: 22px; background: var(--pms-border-2); border-radius: 100px; border: none; cursor: pointer; }
.toggle.on { background: var(--pms-teal); }
.toggle::after { content:''; position:absolute; width:16px; height:16px; border-radius:50%; background:#fff; top:3px; left:3px; transition:transform 0.2s; }
.toggle.on::after { transform: translateX(18px); }
```

---

## Cards

```vue
<!-- Card blanche (composant principal) -->
<div class="card">
  <!-- contenu -->
</div>

<!-- Card sable (fond neutre) -->
<div class="card-sand">
  <!-- contenu -->
</div>

<!-- Stat card (dashboard) -->
<div class="stat-card">
  <div class="stat-label">Taux occupation</div>
  <div class="stat-value">87%</div>
  <div class="stat-delta delta-up">
    <i class="ti ti-trending-up"></i> +4% vs hier
  </div>
</div>

<!-- Carte chambre (plan d'étage) -->
<div class="room-card available">  <!-- ou occupied / cleaning / maintenance -->
  <div class="room-number">101</div>
  <div class="room-type">Standard · 1 lit</div>
  <span class="badge badge-available">...</span>
</div>
```

```css
.card      { background:#fff; border:0.5px solid var(--pms-border); border-radius:var(--radius-lg); padding:1.25rem 1.5rem; }
.card-sand { background:var(--pms-sand); border-radius:var(--radius-lg); padding:1.25rem 1.5rem; }

.stat-card  { background:#fff; border:0.5px solid var(--pms-border); border-radius:var(--radius-md); padding:1.1rem 1.25rem; }
.stat-label { font-size:11px; color:var(--pms-ink-3); font-weight:500; letter-spacing:0.04em; margin-bottom:8px; }
.stat-value { font-size:26px; font-weight:500; color:var(--pms-ink); }
.delta-up   { color: var(--pms-green); }
.delta-down { color: var(--pms-red); }

/* Barre colorée en haut selon le statut */
.room-card { border:0.5px solid var(--pms-border); border-radius:var(--radius-md); padding:14px; background:#fff; position:relative; overflow:hidden; }
.room-card::before { content:''; position:absolute; top:0; left:0; right:0; height:3px; }
.room-card.available::before  { background: var(--pms-green); }
.room-card.occupied::before   { background: var(--pms-red); }
.room-card.cleaning::before   { background: var(--pms-gold); }
.room-card.maintenance::before { background: var(--pms-blue); }
```

---

## Sidebar & Navigation

```vue
<!-- Sidebar principale (fond --pms-ink) -->
<nav class="sidebar">
  <div class="sidebar-logo">
    <i class="ti ti-building"></i> StayOS
  </div>

  <div class="nav-group-label">Principal</div>
  <button class="nav-item active">
    <i class="ti ti-layout-dashboard"></i> Dashboard
  </button>
  <button class="nav-item">
    <i class="ti ti-calendar"></i> Planning
    <span class="nav-badge">3</span>  <!-- badge avec nombre -->
  </button>

  <div class="nav-group-label">Gestion</div>
  <button class="nav-item">
    <i class="ti ti-file-invoice"></i> Facturation
  </button>
</nav>

<!-- Tabs -->
<div class="tabs">
  <button class="tab active">Aujourd'hui</button>
  <button class="tab">Cette semaine</button>
  <button class="tab">Ce mois</button>
</div>

<!-- Onboarding steps -->
<div class="step-circle done"><i class="ti ti-check"></i></div>
<div class="step-circle active">2</div>
<div class="step-circle pending">3</div>
```

---

## Feedback — Toasts & Modales

```vue
<!-- Toasts -->
<div class="toast toast-success">
  <i class="ti ti-circle-check"></i>
  Check-in enregistré pour M. Diallo — Chambre 312
</div>
<div class="toast toast-error"><i class="ti ti-alert-circle"></i>Message d'erreur</div>
<div class="toast toast-warning"><i class="ti ti-alert-triangle"></i>Avertissement</div>
<div class="toast toast-info"><i class="ti ti-info-circle"></i>Information</div>

<!-- Modal (fond backdrop min-height:300px) -->
<div class="modal-backdrop">
  <div class="modal">
    <div class="modal-header">
      <span class="modal-title">Confirmer le check-out</span>
      <button class="btn btn-ghost btn-icon-sm"><i class="ti ti-x"></i></button>
    </div>
    <p><!-- corps --></p>
    <div style="display:flex;gap:8px;justify-content:flex-end;">
      <button class="btn btn-ghost btn-sm">Annuler</button>
      <button class="btn btn-primary btn-sm">Confirmer</button>
    </div>
  </div>
</div>

<!-- État vide -->
<div class="empty-state">
  <i class="ti ti-calendar-off"></i>
  <div class="titre">Aucune réservation</div>
  <div class="sous-titre">Description...</div>
  <button class="btn btn-primary btn-sm">Action principale</button>
</div>
```

---

## Tableau de données

```vue
<div class="table-wrap">
  <table>
    <thead>
      <tr>
        <th>Référence</th>
        <th>Client</th>
        <th>Statut</th>
        <th>Montant</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <!-- Référence en mono -->
        <td><span class="t-mono">RES-04821</span></td>

        <!-- Client avec avatar initiales -->
        <td>
          <div style="display:flex;align-items:center;gap:8px;">
            <div class="avatar avatar-sm avatar-teal">AD</div>
            Amadou Diallo
          </div>
        </td>

        <!-- Badge statut -->
        <td><span class="badge badge-checkin">...</span></td>

        <!-- Montant en gras -->
        <td style="font-weight:500;">135 000 XOF</td>

        <!-- Actions -->
        <td>
          <button class="btn btn-ghost btn-icon-sm" aria-label="Options">
            <i class="ti ti-dots"></i>
          </button>
        </td>
      </tr>
    </tbody>
  </table>
</div>
```

```css
.table-wrap { border:0.5px solid var(--pms-border); border-radius:var(--radius-lg); overflow:hidden; }
table       { width:100%; border-collapse:collapse; }
thead tr    { background: var(--pms-sand); }
th          { font-size:11px; font-weight:500; color:var(--pms-ink-3); text-align:left; padding:11px 16px; letter-spacing:0.04em; }
td          { font-size:13px; color:var(--pms-ink-2); padding:11px 16px; border-top:0.5px solid var(--pms-border); }
tr:hover td { background: #faf9f7; }
```

---

## Avatars

```vue
<!-- Initiales — 3 variantes de couleur -->
<div class="avatar avatar-sm avatar-teal">AD</div>   <!-- 28px -->
<div class="avatar avatar-md avatar-gold">FN</div>   <!-- 38px -->
<div class="avatar avatar-lg avatar-ink">MS</div>    <!-- 52px -->
```

```css
.avatar      { border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:500; }
.avatar-sm   { width:28px; height:28px; font-size:10px; }
.avatar-md   { width:38px; height:38px; font-size:13px; }
.avatar-lg   { width:52px; height:52px; font-size:17px; }
.avatar-ink  { background:var(--pms-ink);       color:#fff; }
.avatar-teal { background:var(--pms-teal-light); color:var(--pms-teal-dark); }
.avatar-gold { background:var(--pms-gold-light); color:var(--pms-gold-dark); }
```

---

## Composants métier spécifiques

### Planning Gantt
```vue
<!-- Barre de réservation colorée selon le statut -->
<div class="gantt-track">
  <div class="gantt-bar" style="left:0%;width:60%;background:var(--pms-teal);">
    Diallo
  </div>
  <div class="gantt-bar" style="left:65%;width:35%;background:var(--pms-gold);">
    Martin
  </div>
</div>
```

### Métriques RevPAR
```vue
<div class="metric-row">
  <div class="metric-key">Lun</div>
  <div class="metric-bar">
    <div class="metric-fill" style="width:92%;background:var(--pms-teal);"></div>
  </div>
  <div class="metric-val">92%</div>
</div>
```

### Cards plan tarifaire SaaS
```vue
<!-- Plan Featured : border 2px solid (seule exception à la règle 0.5px) -->
<div class="plan-card featured">   <!-- border: 2px solid var(--pms-teal) -->
  <span class="badge" style="background:var(--pms-teal-light);color:var(--pms-teal-dark);">
    Populaire
  </span>
  ...
</div>
```

---

## Icônes — Tabler Icons

Bibliothèque : Tabler Icons (outline uniquement — jamais `-filled`)
Import CDN : `https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/dist/tabler-icons.min.css`

```vue
<i class="ti ti-{nom}" aria-hidden="true"></i>
```

### Icônes courantes PMS

| Icône | Usage |
|---|---|
| `ti-layout-dashboard` | Dashboard |
| `ti-calendar` | Planning / dates |
| `ti-bed` | Chambres |
| `ti-users` | Clients |
| `ti-file-invoice` | Facturation |
| `ti-sparkles` | Housekeeping / ménage |
| `ti-chart-bar` | Rapports |
| `ti-building` | Hôtel / logo |
| `ti-login` | Check-in |
| `ti-logout` | Check-out |
| `ti-device-mobile` | Wave / Orange Money |
| `ti-bell` | Notifications |
| `ti-id` | Pièce d'identité |
| `ti-star` | Plan Pro / upgrade |
| `ti-dots` | Menu contextuel |
| `ti-trending-up` | Progression positive |
| `ti-trending-down` | Progression négative |

**Règle** : icône décorative → `aria-hidden="true"` ; icône seule (bouton) → `aria-label` sur le bouton.

---

## Formatage — Montants & Dates

```typescript
// Montants XOF (src/shared/utils/currency.ts)
export function formatXof(amount: string | number): string {
  return new Intl.NumberFormat('fr-SN', {
    style: 'currency',
    currency: 'XOF',
    minimumFractionDigits: 0,
  }).format(Number(amount))
  // → "135 000 F CFA"
}

// Dates (src/shared/utils/date.ts)
import dayjs from 'dayjs'
import 'dayjs/locale/fr'
dayjs.locale('fr')

export const formatDate = (date: string) => dayjs(date).format('DD MMM YYYY')
// → "12 mai 2026"

export const formatDateTime = (date: string) => dayjs(date).format('DD/MM/YYYY à HH:mm')
// → "12/05/2026 à 14h32"
```

---

## Règles à ne jamais enfreindre

1. **Toujours utiliser les variables CSS** — jamais de hex codé en dur dans les composants
2. **Bordures à 0.5px** — sauf pour les cards featured (2px) et les focus rings
3. **Pas de box-shadow** sauf focus rings (`0 0 0 3px rgba(...)`)
4. **Sentence case partout** — jamais de Title Case dans les labels
5. **Montants en string** — jamais de `number` pour les XOF (erreurs float)
6. **Mono pour les références** — `RES-04821`, `FAC-00142` → toujours `font-family: var(--mono)`
7. **Badge-dot obligatoire** pour tous les statuts dynamiques (chambres, réservations)
8. **Pas de gradients** sur les composants UI — fond plat uniquement
