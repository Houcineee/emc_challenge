# Billetterie d'une salle de spectacle

PFE Challenge EMC, sujet 1. Symfony 7.4, PHP 8.2+, MySQL 8.

## Niveaux

| Niveau | Thème | État |
|---|---|---|
| 1 | Modéliser | Fait |
| 2 | Faire respecter les règles | Fait |
| 3 | En faire un produit | Fait |
| 4 | API externe (Google Sheets) | À faire |
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

