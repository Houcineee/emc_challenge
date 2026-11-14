# Théâtre Royal

Application de billetterie Symfony 7.4 pour une salle de spectacle de 400 places.

## Niveaux

| Niveau | Thème | État |
|---|---|---|
| 1 | Modéliser | Fait |
| 2 | Faire respecter les règles | Fait |
| 3 | En faire un produit | Fait |
| 4 | API externe (Google Sheets) | Fait |
| 5 | Livrer (Docker, INSTALL.md) | Fait |

## Démarrage

Prérequis : Docker Engine, Docker Compose v2 et Git.

```bash
chmod +x bin/install && ./bin/install
```

Cette commande crée la configuration locale, construit les conteneurs, installe les dépendances,
applique les migrations, charge les données de démonstration et démarre le scheduler.

Application : <http://localhost:8000>

Guide complet : [`INSTALL.md`](INSTALL.md)

## Comptes de démonstration

| Rôle | Email | Mot de passe |
|---|---|---|
| Client | `alice@client.ma` | `Client123!` |
| Guichetier | `guichet@theatre.ma` | `Guichet123!` |
| Administrateur | `admin@theatre.ma` | `Admin123!` |

## Fonctionnalités principales

- Parcours d'achat avec réservation temporaire Redis de 15 minutes.
- Salle 3D interactive avec configurations théâtre, concert et conférence.
- Coupons, idempotence de paiement et protection contre la double vente.
- Billets PDF avec QR code, espace client et contrôle cashier.
- Tableaux de bord administrateur et guichetier.
- Synchronisation automatique des ventes et remboursements vers Google Sheets.

