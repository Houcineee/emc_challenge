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
