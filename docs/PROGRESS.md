# État d'avancement et Guide de reprise de session

Ce document synthétise l'état exact du projet pour permettre à tout agent ou développeur d'enchaîner directement sur les étapes suivantes sans perte de contexte.

---

## 1. État des Niveaux

| Niveau | Thème | Statut | Détails |
|---|---|---|---|
| **1** | **Modéliser** | **FAIT** | 4 Enums, 8 Entités Doctrine (3NF), Migration appliquée, 400 sièges et données de démo chargées. |
| **2** | **Faire respecter les règles** | **FAIT** | Verrou temporaire 15 min (Redis), service de billetterie transactionnel MySQL, idempotence stricte au paiement (`Idempotency-Key`), API REST complète (19 routes), 21 tests automatisés PHPUnit (125 assertions, 100% au vert). |
| **3** | **En faire un produit** | **FAIT** | Plan de salle 3D Three.js procédural interactif, parcours d'achat complet, billet QR code, interfaces guichet et admin. |
| **4** | **API externe (Google Sheets)** | **FAIT** | Outbox transactionnel, synchronisation append-only, feuille configurée par variable d'environnement et reprise automatique des échecs. |
| **5** | **Livrer (Docker, INSTALL.md)** | **EN COURS** | Stack Docker et scheduler local opérationnels, documentation README disponible ; packaging final et guide INSTALL.md restent à finaliser. |

---

## 2. Comptes de test & Identifiants (Fixtures chargées)

Tous les comptes ont été créés via `AppFixtures` avec des mots de passe hachés :

| Rôle | Email | Mot de passe | Usage |
|---|---|---|---|
| **ADMIN** | `admin@theatre.ma` | `Admin123!` | Direction de la salle (création d'événements, gestion des prix, annulations). |
| **CASHIER** | `guichet@theatre.ma` | `Guichet123!` | Guichetier (vente physique sur place, téléphone). |
| **CLIENT** | `alice@client.ma` | `Client123!` | Acheteur en ligne standard. |
| **CLIENT** | `bob@client.ma` | `Client123!` | Acheteur en ligne standard. |
| **CLIENT** | `charlie@client.ma` | `Client123!` | Acheteur en ligne standard. |
| **CLIENT** | `salma@client.ma` | `Client123!` | Acheteur en ligne standard. |

---

## 3. Infrastructure & Environnement technique

- **Conteneurs Docker actifs (`docker compose up -d`)** :
  - `emc_app` : PHP 8.2 CLI + serveur de développement Symfony sur `http://localhost:8000`.
  - `emc_mysql` : MySQL 8.0 (port réseau interne Docker : `3306`, port exposé sur l'hôte : `3307` pour éviter les conflits avec un MySQL local).
- **Base de données (`ticketing`)** :
  - Utilisateur : `app`
  - Mot de passe : `app_local_pass` (défini dans `.env.local`)
  - URL Doctrine : `mysql://app:app_local_pass@mysql:3306/ticketing?serverVersion=8.0&charset=utf8mb4`

---

## 4. Données physiques et modélisation en base

- **Capacité de la salle** : exactement **400 sièges physiques** dans la table `seats`.
- **5 Zones physiques (`zones`)** :
  1. `FRONT_STAGE` : 80 places, multiplicateur de prix `+20%`.
  2. `ORCHESTRA` : 160 places, multiplicateur de prix `+50%`.
  3. `LEFT_BOX` : 30 places, multiplicateur de prix `+100%`.
  4. `RIGHT_BOX` : 30 places, multiplicateur de prix `+100%`.
  5. `BALCONY` : 100 places, multiplicateur de prix `+0%` (prix de base de l'événement).
- **3 Types d'événements (`event_types`)** :
  - `CONCERT` : `FRONT_STAGE` sans sièges (`is_seated = false`, fosse debout), les 4 autres zones assises.
  - `THEATRE` : 100% assises (`is_seated = true` sur les 5 zones).
  - `CONFERENCE` : 100% assises.
- **Formule de calcul du prix** :
  $$\text{Prix place} = \text{events.base\_price\_cents} \times \frac{100 + \text{zones.price\_multiplier\_percent}}{100}$$
  Le prix payé net (après éventuel coupon) est figé dans `tickets.final_price_cents`.
- **Bouclier anti-double-vente** : contrainte `UNIQUE(event_id, seat_id)` sur la table `tickets`.

---

## 5. Commandes utiles pour l'agent

```bash
# Vérifier la validité du schéma et la synchronisation MySQL
docker exec emc_app php bin/console doctrine:schema:validate

# Recharger les données de test à zéro (si besoin)
docker exec emc_app php bin/console doctrine:fixtures:load --no-interaction

# Lancer la suite de tests
docker exec emc_app php bin/phpunit
```

---

## 6. Prochaine étape : finaliser le Niveau 5 (Livrer)

Pour finaliser le Niveau 5 :
1. Finaliser le guide d'installation dédié si nécessaire.
2. Vérifier le déploiement complet de la stack Docker sur l'environnement cible.
3. Documenter les variables secrètes et la stratégie de lancement du scheduler en production.
