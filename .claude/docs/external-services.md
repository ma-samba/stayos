# Services externes — Référence complète

---

## Paydunya — Paiement

**Rôle** : passerelle de paiement unique pour Wave, Orange Money, cartes bancaires.
**Pourquoi Paydunya plutôt que les APIs directes** : une seule intégration couvre tous les moyens de paiement locaux + cartes internationales. Sandbox disponible.

**Docs** : https://paydunya.com/developers

### Flux de paiement (checkout)

```
1. Backend crée une "invoice" Paydunya → reçoit une checkout_url
2. Frontend redirige le client vers checkout_url (ou iframe)
3. Client paie (Wave / OM / carte)
4. Paydunya appelle le webhook → POST /api/webhooks/paydunya
5. Backend vérifie la signature → enregistre le Payment en BDD
6. Mercure notifie le frontend en temps réel
```

### Configuration (.env.local)
```bash
PAYDUNYA_MASTER_KEY=your_master_key
PAYDUNYA_PRIVATE_KEY=your_private_key
PAYDUNYA_TOKEN=your_token
PAYDUNYA_MODE=test   # test | live
```

### Service PHP (src/Shared/Payment/PaydunyaService.php)
```php
class PaydunyaService
{
    // Crée une invoice Paydunya et retourne l'URL de checkout
    public function createCheckout(
        string     $invoiceNumber,
        string     $amountXof,
        string     $description,
        string     $customerEmail,
        string     $returnUrl,
        string     $cancelUrl
    ): array
    // Retourne : ['checkout_url' => '...', 'token' => '...']

    // Vérifie le statut d'un paiement depuis le token
    public function verifyPayment(string $token): array
    // Retourne : ['status' => 'completed|pending|failed', 'amount' => '...']

    // Vérifie la signature d'un webhook entrant
    public function verifyWebhookSignature(Request $request): bool
}
```

### Webhook endpoint
```php
// POST /api/webhooks/paydunya
// Vérifier signature → chercher invoice par référence → enregistrer Payment
class PaydunyaWebhookController
{
    public function __invoke(Request $request): JsonResponse
    {
        if (!$this->paydunyaService->verifyWebhookSignature($request)) {
            return new JsonResponse(['error' => 'Invalid signature'], 401);
        }

        $data = json_decode($request->getContent(), true);
        $token = $data['data']['invoice']['token'];
        $status = $data['data']['invoice']['status']; // completed | pending | failed

        // Trouver la SaasInvoice ou Invoice hôtel par token
        // Enregistrer le Payment
        // Publier sur Mercure si paiement confirmé
    }
}
```

### Variables de test (sandbox Paydunya)
```
Numéro Wave test    : +221 77 000 00 00
Numéro OM test      : +221 76 000 00 00
Carte test          : 4111 1111 1111 1111 / 12/25 / 123
```

---

## Uploadcare — CDN Images

**Rôle** : stockage et diffusion des images (logos hôtels, photos chambres, documents clients scannés).
**Pourquoi Uploadcare** : filesystem éphémère sur Heroku → impossible de stocker localement. Uploadcare gère l'upload, la transformation (resize, crop, WebP) et le CDN.

**Docs** : https://uploadcare.com/docs/

### Configuration
```bash
UPLOADCARE_PUBLIC_KEY=your_public_key
UPLOADCARE_SECRET_KEY=your_secret_key
```

### Utilisation Frontend (upload direct depuis le navigateur)

```typescript
// src/services/uploadcare.service.ts
// L'upload se fait DIRECTEMENT depuis le navigateur vers Uploadcare
// → pas de transit par le backend → pas de charge serveur

import { uploadFile } from '@uploadcare/upload-client'

export async function uploadImage(file: File): Promise<string> {
  const result = await uploadFile(file, {
    publicKey: import.meta.env.VITE_UPLOADCARE_PUBLIC_KEY,
    store: 'auto',
  })
  return result.cdnUrl  // ex: https://ucarecdn.com/uuid/
}

// Usage dans un composant Vue :
// const url = await uploadImage(file)
// await roomService.update(roomId, { photoUrl: url })
// → on envoie au backend seulement l'URL CDN finale
```

### Widget Vue.js (upload avec preview)

```vue
<!-- src/shared/components/ui/ImageUpload.vue -->
<template>
  <div class="image-upload">
    <img v-if="modelValue" :src="modelValue + '-/resize/400x300/'" alt="Preview" />
    <input type="file" accept="image/*" @change="handleUpload" />
    <span v-if="uploading">Envoi en cours...</span>
  </div>
</template>

<script setup lang="ts">
const props = defineProps<{ modelValue?: string }>()
const emit = defineEmits<{ 'update:modelValue': [url: string] }>()

const uploading = ref(false)

async function handleUpload(e: Event) {
  const file = (e.target as HTMLInputElement).files?.[0]
  if (!file) return
  uploading.value = true
  const url = await uploadImage(file)
  emit('update:modelValue', url)
  uploading.value = false
}
</script>
```

### Transformations URL Uploadcare

```
// Image originale
https://ucarecdn.com/{uuid}/

// Redimensionner
https://ucarecdn.com/{uuid}/-/resize/800x600/

// Crop centré
https://ucarecdn.com/{uuid}/-/scale_crop/400x300/center/

// WebP automatique
https://ucarecdn.com/{uuid}/-/format/webp/

// Thumbnail chambre (usage courant)
https://ucarecdn.com/{uuid}/-/resize/400x300/-/format/webp/
```

### Entités concernées

```php
// Room → photoUrls (JSON array d'URLs Uploadcare)
// HotelProfile → logoUrl, coverPhotoUrl
// Guest → documentPhotoUrl (photo CNI/passeport scanné)
// StaffUser → avatarUrl
```

---

## Mailjet — Emails transactionnels

**Rôle** : envoi de tous les emails (confirmations, OTP, factures, rappels).
**Pourquoi Mailjet** : API française fiable, excellent deliverability Afrique, templates HTML, sandbox gratuit.

**Docs** : https://dev.mailjet.com/

### Configuration
```bash
MAILJET_API_KEY=your_api_key
MAILJET_API_SECRET=your_api_secret
MAILER_DSN=mailjet+api://${MAILJET_API_KEY}:${MAILJET_API_SECRET}@default
# En dev local → remplacer par : smtp://mailpit:1025
```

### Symfony Mailer — intégration

```php
// Symfony Mailer supporte Mailjet nativement via symfony/mailjet-mailer
// Injecter MailerInterface dans les services

class EmailService
{
    public function __construct(private MailerInterface $mailer) {}

    // Confirmation de réservation
    public function sendReservationConfirmation(Reservation $reservation): void
    {
        $email = (new TemplatedEmail())
            ->from(new Address('noreply@stayos.sn', 'StayOS'))
            ->to($reservation->getGuest()->getEmail())
            ->subject('Confirmation de votre réservation — ' . $reservation->getConfirmationNumber())
            ->htmlTemplate('emails/reservation_confirmation.html.twig')
            ->context([
                'reservation'  => $reservation,
                'hotel'        => $reservation->getRoom()->getHotel(),
                'checkin_date' => $reservation->getCheckIn()->format('d/m/Y'),
                'checkout_date'=> $reservation->getCheckOut()->format('d/m/Y'),
            ]);

        $this->mailer->send($email);
    }

    // OTP (vérification email lors de l'onboarding)
    public function sendOtp(string $email, string $otp): void
    {
        $email = (new Email())
            ->from('noreply@stayos.sn')
            ->to($email)
            ->subject('Votre code de vérification StayOS')
            ->text("Votre code : $otp (valable 10 minutes)");

        $this->mailer->send($email);
    }

    // Facture PDF en pièce jointe
    public function sendInvoice(Invoice $invoice, string $pdfPath): void
    {
        $email = (new TemplatedEmail())
            ->from(new Address('noreply@stayos.sn', 'StayOS'))
            ->to($invoice->getReservation()->getGuest()->getEmail())
            ->subject('Votre facture — ' . $invoice->getNumber())
            ->htmlTemplate('emails/invoice.html.twig')
            ->context(['invoice' => $invoice])
            ->attachFromPath($pdfPath, $invoice->getNumber() . '.pdf');

        $this->mailer->send($email);
    }
}
```

### Templates emails (backend/templates/emails/)

```
emails/
├── reservation_confirmation.html.twig
├── reservation_cancellation.html.twig
├── invoice.html.twig
├── otp.html.twig
├── subscription_trial_ending.html.twig
└── subscription_suspended.html.twig
```

### En dev local — Mailpit

Mailpit intercepte tous les emails sans les envoyer.
Interface : http://localhost:8025
Ne jamais changer `MAILER_DSN` en dev — Mailpit est automatiquement configuré via Docker.

---

## Amazon RDS — PostgreSQL production

**Rôle** : base de données managée en production. Remplace le conteneur PostgreSQL Docker qui reste uniquement pour le dev local.

**Pourquoi RDS** : backups automatiques, multi-AZ possible, snapshots, monitoring CloudWatch, pas de gestion de serveur.

### Configuration production
```bash
# URL fournie par AWS RDS lors de la création de l'instance
DATABASE_URL=postgresql://stayos_user:password@hostname.rds.amazonaws.com:5432/stayos_prod?serverVersion=16&charset=utf8
```

### Paramètres RDS recommandés
```
Engine         : PostgreSQL 16
Instance       : db.t3.small (dev/staging) → db.t3.medium (prod)
Storage        : 20 GB SSD gp3, auto-scaling activé
Multi-AZ       : Non (dev) → Oui (prod)
Backup         : 7 jours de rétention automatique
Region         : eu-west-1 (Irlande) — latence correcte depuis Dakar
VPC            : Restreindre l'accès à l'IP du dyno Heroku uniquement
```

### Connexion depuis Heroku
```bash
# Sur Heroku, configurer la variable d'environnement :
heroku config:set DATABASE_URL="postgresql://..."

# Lancer les migrations depuis Heroku
heroku run php bin/console doctrine:migrations:migrate --no-interaction
```

### Multi-schema sur RDS
RDS PostgreSQL supporte nativement les schemas — aucune différence avec le dev local.
Le `TenantMiddleware` fonctionne exactement pareil : `SET search_path TO hotel_{uuid}, public`.

### Sauvegardes
RDS gère les backups automatiques. En plus, snapshot manuel avant chaque migration importante :
```bash
# Via AWS CLI
aws rds create-db-snapshot \
  --db-instance-identifier stayos-prod \
  --db-snapshot-identifier stayos-pre-migration-$(date +%Y%m%d)
```

---

## Récapitulatif — Variables d'environnement

### Dev local (.env.local)
```bash
# BDD locale (Docker)
DATABASE_URL=postgresql://stayos_user:stayos_password@db:5432/stayos_db?serverVersion=16

# Mail local (Mailpit)
MAILER_DSN=smtp://mailpit:1025

# Paydunya sandbox
PAYDUNYA_MASTER_KEY=test_master_key
PAYDUNYA_PRIVATE_KEY=test_private_key
PAYDUNYA_TOKEN=test_token
PAYDUNYA_MODE=test

# Uploadcare
UPLOADCARE_PUBLIC_KEY=your_public_key
UPLOADCARE_SECRET_KEY=your_secret_key
VITE_UPLOADCARE_PUBLIC_KEY=your_public_key
```

### Production (Heroku config vars)
```bash
# BDD RDS
DATABASE_URL=postgresql://user:pass@rds-host.amazonaws.com:5432/stayos_prod?serverVersion=16

# Mail Mailjet
MAILER_DSN=mailjet+api://api_key:api_secret@default

# Paydunya live
PAYDUNYA_MASTER_KEY=live_master_key
PAYDUNYA_PRIVATE_KEY=live_private_key
PAYDUNYA_TOKEN=live_token
PAYDUNYA_MODE=live

# Uploadcare
UPLOADCARE_PUBLIC_KEY=your_public_key
UPLOADCARE_SECRET_KEY=your_secret_key

# Redis (Heroku addon)
REDIS_URL=redis://...

# JWT
JWT_PASSPHRASE=your_passphrase
APP_SECRET=your_secret
APP_ENV=prod
APP_DEBUG=0
```

### Frontend Vercel (environment variables)
```bash
VITE_API_URL=https://your-app.herokuapp.com/api
VITE_MERCURE_URL=https://your-mercure.herokuapp.com/.well-known/mercure
VITE_UPLOADCARE_PUBLIC_KEY=your_public_key
VITE_APP_DOMAIN=getstayos.com
```
