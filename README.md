# Billetterie d'une salle de spectacle

PFE Challenge EMC, sujet 1. Symfony 7.4, PHP 8.2+, MySQL 8.

## Niveaux

| Niveau | Thème | État |
|---|---|---|
| 1 | Modéliser | Fait |
| 2 | Faire respecter les règles | À faire |
| 3 | En faire un produit | À faire |
| 4 | API externe (Google Sheets) | À faire |
| 5 | Livrer (Docker, INSTALL.md) | À faire |

## Lancer le projet

1. **Démarrer les conteneurs (PHP 8.2 & MySQL 8.0)** :
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
4. **Accéder à l'application** :
   L'application répond sur `http://localhost:8000`.
