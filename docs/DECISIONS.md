# Journal des décisions

Format : date, contexte, options, choix, conséquence.

## Questions à trancher

- [x] Le prix d'un billet déjà vendu change-t-il si le tarif est modifié ensuite ?
- [x] Une représentation peut-elle utiliser une configuration de salle différente ?
- [x] Que devient une place dont le paiement n'aboutit pas ?
- [x] Que se passe-t-il pour les billets vendus si une représentation est annulée ?
- [x] Qui a le droit de vendre à quel tarif ?

---

## 2026-11-10 - D1 : Rôles et gestion des tarifs

**Contexte.** La direction souhaite une billetterie en ligne utilisable également par le guichet de la salle (actuellement géré sur papier). Il faut clarifier les rôles et l'accès aux tarifs.

**Options.**
1. Multiplier les profils avec des grilles tarifaires distinctes et des règles d'autorisation complexes.
2. Définir 3 rôles stricts (`CLIENT`, `CASHIER`, `ADMIN`). L'administrateur définit le prix de base de l'événement et crée les codes coupons. Le guichetier vend au comptoir aux tarifs définis ou applique des coupons spécifiques. Le client achète en ligne.

**Choix.** Option 2.

**Conséquence.** La table `users` n'admet qu'un seul rôle par utilisateur via l'enum `UserRole`. Les remises (étudiants, invitations, promotions de dernière minute) transitent uniformément par le système de coupons (`coupons`).

---

## 2026-11-10 - D2 : Immuabilité du prix d'un billet vendu

**Contexte.** Le prix de base d'un événement ou la réduction d'un coupon peut être ajusté au cours de la période de vente. Les billets déjà achetés doivent impérativement conserver leur valeur d'achat historique.

**Options.**
1. Recalculer le prix dynamiquement par jointure sur l'événement, la zone et le coupon.
2. Figer le montant final réellement payé dans `tickets.final_price_cents` au moment de la transaction.

**Choix.** Option 2.

**Conséquence.** Le montant payé est immuable dès l'émission du billet. Toute modification de tarif n'affecte que les futures ventes.

---

## 2026-11-10 - D3 : Configurations de salle et tarification par multiplicateur

**Contexte.** La salle unique de 400 places accueille des concerts, du théâtre et des conférences. Selon le type de spectacle, certaines zones passent en fosse debout ou en places assises. Il faut éviter l'anti-pattern des colonnes booléennes ou des tables déconnectées.

**Options.**
1. Multiplier les colonnes booléennes et de prix dans `events` (`front_stage_price`, `orchestra_price`, etc.).
2. Normaliser en 3NF :
   - Table `event_types` (`CONCERT`, `THEATRE`, `CONFERENCE`).
   - Table `configurations` reliant `event_types` et `zones` avec le booléen `is_seated`.
   - Multiplicateur de prix dans `zones.price_multiplier_percent` appliqué sur `events.base_price_cents`.

**Choix.** Option 2.

**Conséquence.** Zéro anti-pattern 1NF. Intégrité référentielle complète via clés étrangères. Chaque place reste matérialisée dans `seats` (`seat_id NOT NULL` sur `tickets`) assurant l'efficacité de la contrainte `UNIQUE(event_id, seat_id)`. Le numéro est simplement masqué sur le billet si `is_seated = false`.

---

## 2026-11-10 - D4 : Verrou temporaire de 15 min, concurrence et abandon

**Contexte.** Éviter les doubles ventes sans bloquer le moteur MySQL InnoDB avec des milliers de réservations expirées ou des nettoyages concurrents complexes.

**Options.**
1. Gérer les paniers temporaires dans MySQL avec des verrous pessimistes et des statuts `RESERVED` expirés.
2. Isoler les réservations temporaires de 15 min dans Redis via `SET hold:event:{id}:seat:{id} {userId} NX EX 900`, et ne persister dans MySQL que les ventes effectives.

**Choix.** Option 2.

**Conséquence.** Le verrou atomique Redis garantit l'exclusion mutuelle sans encombrer la base relationnelle. L'expiration est gérée naturellement par le TTL Redis. La table `tickets` ne conserve que les ventes réelles sous protection de la contrainte `UNIQUE(event_id, seat_id)`.

---

## 2026-11-10 - D5 : Annulation d'un événement

**Contexte.** La direction peut être amenée à annuler un spectacle dont des places sont déjà payées ou en cours de réservation.

**Options.**
1. Supprimer les enregistrements de billets en base.
2. Passer `events.status` à `CANCELLED`, basculer tous les billets associés au statut `REFUNDED` pour conservation comptable, et purger les clés temporaires associées dans Redis.

**Choix.** Option 2.

**Conséquence.** L'historique des ventes reste auditable. Aucune nouvelle réservation n'est acceptée pour un événement annulé.

---

## 2026-11-11 - D6 : Architecture Front-End & Rendu Spatial 3D (Three.js) sans dépendance Node.js

**Contexte.** Le Niveau 3 ("En faire un produit") exige une expérience utilisateur raffinée, une visualisation spatiale fidèle des 400 places modulables (fosse debout pour les concerts vs sièges pour le théâtre), et une simplicité d'exécution absolue pour les évaluateurs (Niveau 5).

**Options.**
1. Développer une SPA React ou Vue avec outillage npm/Vite lourd nécessitant une compilation front-end distincte.
2. Architecture Twig Server-Driven avec Vanilla JavaScript moderne, Three.js r128 chargé via CDN, Tailwind CSS et composant de rendu procédural WebGL.

**Choix.** Option 2.

**Conséquence.**
- Zéro étape de build Node.js : `docker compose up -d` suffit pour faire tourner 100% de la stack sans installer npm sur la machine hôte.
- Fluidité WebGL 60fps : rendu 3D procédural précis de la salle (scène avec proscénium, orchestre incurvé à 8 rangées, loges privées suspendues, balcon haut et fosse dynamique adaptative).
- Intégration native avec les sessions Symfony et mise à disposition d'une barre de démonstration 1-clic pour changer de rôle (`ADMIN`, `CASHIER`, `CLIENT`) instantanément.
- Sécurisation des transactions via génération de clés d'idempotence UUID v4 et minuteurs de compte à rebours synchronisés avec le TTL Redis (15 minutes).

## 2026-11-14 - D7 : Synchronisation Google Sheets par outbox transactionnel

**Contexte.** Le Niveau 4 exige que les ventes soient synchronisées automatiquement dans une feuille
Google Sheets partagée avec la direction, sans bloquer ni perdre une vente si Google est indisponible.

**Options.**
1. Écrire directement dans Google Sheets pendant la transaction d'achat.
2. Enregistrer la vente et un message d'outbox dans la même transaction MySQL, puis synchroniser les
   messages en arrière-plan.

**Choix.** Option 2, avec un historique append-only : une ligne `SOLD` à la vente et une ligne
`REFUNDED` en cas de remboursement. La base de données reste la source de vérité.

**Conséquence.** Une panne Google ne fait pas échouer l'achat. Les messages non traités restent dans
`outbox_messages` et sont retentés automatiquement lors de l'exécution suivante. Les lignes déjà
traitées ne sont jamais ajoutées deux fois.

## 2026-11-14 - D8 : Compte de service et feuille Google préexistante

**Contexte.** Un compte de service Google nouvellement créé peut avoir un quota Drive nul et ne peut
donc pas créer de fichier, même si les APIs Google Sheets et Drive sont activées.

**Options.**
1. Demander un compte Google Workspace ou un quota Drive permettant la création automatique.
2. Configurer l'identifiant d'une feuille existante via une variable d'environnement et donner au
compte de service le rôle `Editor`.

**Choix.** Option 2 pour conserver un workflow simple avec un compte Gmail personnel :
`GOOGLE_SHEETS_ID` contient l'identifiant de la feuille existante.

**Conséquence.** L'application prépare l'onglet `Ventes`, ajoute les en-têtes et synchronise les
ventes dans cette feuille. Aucun identifiant de feuille ni secret n'est codé dans le dépôt.

## 2026-11-14 - D9 : Déclenchement local automatique par service Docker

**Contexte.** Les évaluateurs doivent pouvoir tester la synchronisation sans configurer manuellement
un cron sur leur machine.

**Options.**
1. Demander à chaque évaluateur d'installer une tâche cron sur son hôte.
2. Ajouter un service `scheduler` à Docker Compose qui exécute la commande de synchronisation chaque
minute et redémarre automatiquement.

**Choix.** Option 2.

**Conséquence.** `docker compose up -d` démarre l'application, la base, Redis et le scheduler.
Le scheduler lance `app:sync-google-sheets` toutes les minutes. La même approche fonctionne dans
un environnement hébergeant des services Docker persistants.

