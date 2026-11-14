# Billetterie d'une salle de spectacle

PFE Challenge EMC, sujet 1. Symfony 7.4, PHP 8.2+, MySQL 8.

## Niveaux

| Niveau | Thème | État |
|---|---|---|
| 1 | Modéliser | Fait |
| 2 | Faire respecter les règles | Fait |
| 3 | En faire un produit | Fait |
| 4 | API externe (Google Sheets) | Implémentation prête à configurer |
| 5 | Livrer (Docker, INSTALL.md) | En cours |

## Lancer le projet

1. **Démarrer les conteneurs (PHP 8.2, MySQL 8.0 & Redis 7)** :
   ```bash
   docker compose up -d
   ```
2. **Appliquer les migrations de base de données** :
   ```bash
   docker exec emc_app php bin/console doctrine:migrations:migrate --no-interaction
   ```
3. **Charger les données de démonstration (400 places, 4 événements, ventes)** :
   ```bash
   docker exec emc_app php bin/console doctrine:fixtures:load --no-interaction
   ```
4. **Exécuter la suite de tests automatisés (21 tests, 125 assertions)** :
   ```bash
   docker exec emc_app php bin/phpunit
   ```
5. **Accéder à l'application web** :
   L'application répond directement sur `http://localhost:8000`.

## Fonctionnalités du Produit (Niveau 3)

- **Esthétique Théâtre Sombre & Or** : Design haut de gamme avec polices Google Fonts (*Playfair Display* & *Plus Jakarta Sans*), Tailwind CSS, effets de glassmorphisme et thème sombre "Nuit d'Or".
- **Plan de Salle 3D Interactif (Three.js WebGL)** :
  - Visualisation en 3D temps réel des 400 places de la salle (Scène avec proscénium, Orchestre incurvé en amphithéâtre, Loges VIP privées suspendues, Balcon haut et Fosse avant-scène).
  - Adaptation spatiale selon la configuration : Fosse debout pour les concerts (`CONCERT`) vs rangées de sièges pour les pièces de théâtre et conférences (`THEATRE`, `CONFERENCE`).
  - Préréglages de caméra 1-clic : *Vue Scène*, *Vue du Dessus (Plan 2D)*, *Vue Panoramique 3D*.
  - Raycasting interactif avec infobulle au survol et réservation immédiate au clic (verrouillage garanti 15 minutes via Redis).
- **Tunnel de Commande & Idempotence** :
  - Récapitulatif du panier avec compte à rebours dynamique du TTL Redis.
  - Validation dynamique des codes promotionnels (`VIP20`, `EARLY10`).
  - Validation du paiement sécurisée par une clé d'idempotence UUID v4 (`Idempotency-Key`) empêchant tout double-débit.
- **Portefeuille de Billets avec QR Code** :
  - Billets officiels au format carte d'embarquement de luxe avec bordure prédécoupée.
  - Génération vectorielle du QR code d'accès officiel (`EMC-{eventId}-{ticketId}`).
  - Fonction d'impression optimisée via feuille de style dédiée (`@media print`).
- **Tableaux de Bord Évaluateur 1-Clic** :
  - Barre d'évaluation supérieure permettant de basculer instantanément entre les profils `ADMIN`, `CASHIER` et `CLIENT`.
  - **Dashboard Administrateur (`/admin`)** : Chiffre d'affaires en MAD, taux d'occupation par spectacle, création de spectacles/coupons et annulation avec remboursement en cascade.
  - **Guichet POS & Contrôle d'Accès (`/cashier`)** : Simulateur de scanner de QR code en direct vérifiant la validité des billets à l'entrée de la salle.

## Synchronisation Google Sheets (Niveau 4)

Les ventes sont enregistrées dans un outbox transactionnel avec les actions `SOLD` et `REFUNDED`.
La commande `app:sync-google-sheets` lit les messages non traités et les ajoute automatiquement dans
un onglet `Ventes`. Une erreur est journalisée et le message reste non traité pour la prochaine exécution.

### Configuration initiale

1. Dans Google Cloud Console, créez un projet ou sélectionnez un projet existant.
2. Activez **Google Sheets API** et **Google Drive API** dans **APIs & Services > Library**.
3. Dans **IAM & Admin > Service Accounts**, créez un compte de service.
4. Dans l'onglet **Keys** de ce compte, choisissez **Add key > Create new key > JSON**.
5. Placez le fichier téléchargé dans `var/secrets/google-service-account.json`.
6. Ne commitez jamais ce fichier et ne partagez jamais sa clé privée.

Configuration locale dans `.env.local`, sans committer de secret :

```dotenv
GOOGLE_SERVICE_ACCOUNT_JSON=/app/var/secrets/google-service-account.json
GOOGLE_SHEETS_TITLE="Théâtre Royal — Ventes"
GOOGLE_SHEETS_DIRECTOR_EMAIL=direction@example.com
```

Le premier traitement qui suit une vente crée la feuille, ajoute les colonnes, puis la partage avec
l'adresse de la direction. La feuille créée est visible dans **Google Drive > Shared with me** du
compte configuré dans `GOOGLE_SHEETS_DIRECTOR_EMAIL`.

### Vérification manuelle

1. Achetez un billet depuis l'application.
2. Exécutez :

   ```bash
   docker compose exec -T app php bin/console app:sync-google-sheets --no-interaction
   ```

3. Ouvrez Google Drive avec l'adresse configurée et recherchez le titre de la feuille.
4. Vérifiez l'onglet `Ventes` et la ligne de vente.

Pour donner l'accès à d'autres personnes, ouvrez la feuille, cliquez sur **Share**, ajoutez leurs
adresses Google, puis choisissez `Viewer`, `Commenter` ou `Editor`. Elles n'ont pas besoin d'accéder
au projet Google Cloud.

Pour une exécution automatique, planifier la commande toutes les minutes :

```cron
* * * * * docker compose exec -T app php bin/console app:sync-google-sheets --no-interaction
```

### Dépannage

Si la commande affiche une erreur `Google Sheets API has not been used in project ... or it is
disabled`, activez **Google Sheets API** dans le projet Google Cloud associé au fichier JSON du
compte de service. Activez également **Google Drive API** dans ce même projet, puis attendez quelques
minutes avant de relancer la commande.

Les messages en échec restent non traités dans l'outbox et sont automatiquement retentés lors de la
prochaine exécution :

```bash
docker compose exec -T app php bin/console app:sync-google-sheets --no-interaction
```

