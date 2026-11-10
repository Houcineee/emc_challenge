# Journal des décisions

Format : date, contexte, options, choix, conséquence.

## Questions à trancher

- [x] Le prix d'un billet déjà vendu change-t-il si le tarif est modifié ensuite ?
- [x] Une représentation peut-elle utiliser une configuration de salle différente ?
- [x] Que devient une place dont le paiement n'aboutit pas ?
- [x] Que se passe-t-il pour les billets vendus si une représentation est annulée ?
- [x] Qui a le droit de vendre à quel tarif ?

---

## 2026-11-10 - D1 : Rôles et gestion des tarifs par coupons

**Contexte.** Il faut déterminer qui fixe les prix et qui peut accorder des tarifs réduits, étudiants, gratuits ou de dernière minute. Multiplier les types de tarifs par rôle complique l'interface et les règles d'autorisation.

**Options.**
1. Créer une grille tarifaire par rôle (`ROLE_USER`, `ROLE_CASHIER`, `ROLE_ADMIN`) avec des tarifs réservés au guichet.
2. Définir 3 rôles (`Buyer`, `Organizer`, `Admin`). L'organisateur fixe un prix de base par zone et émet des coupons de réduction (étudiant, invitation à 100 %, promotion automatique 1h avant le spectacle). L'administrateur gère la plateforme mais n'intervient ni sur les prix ni sur les billets.

**Choix.** Option 2.

**Conséquence.** Un tarif réduit ou une invitation gratuite est simplement un coupon créé par l'organisateur de l'événement. Un acheteur paie le plein tarif par défaut ou fournit un coupon valide. L'administrateur ne peut pas émettre de billets gratuits.

---

## 2026-11-10 - D2 : Immuabilité du prix d'un billet vendu

**Contexte.** Un organisateur peut modifier le prix d'une catégorie ou appliquer une remise automatique de dernière minute une heure avant la séance. Les billets achetés avant ce changement ne doivent pas voir leur montant historique altéré.

**Options.**
1. Calculer le prix d'un billet dynamiquement par jointure sur le tarif de la zone et le coupon.
2. Figer le prix final payé directement dans une colonne de la table `ticket` au moment de l'achat.

**Choix.** Option 2.

**Conséquence.** La table `ticket` stocke le montant payé au moment de la validation du paiement. Toute modification ultérieure du prix de la zone ou du pourcentage d'un coupon ne s'applique qu'aux nouveaux achats.

---

## 2026-11-10 - D3 : Configurations de salle et zones avec ou sans siège

**Contexte.** Une même salle accueille des spectacles tout assis et des concerts avec fosse debout. Si les places debout n'ont pas de `seat_id` dans `ticket`, la colonne devient nullable. En MySQL, `NULL != NULL` désactive la contrainte `UNIQUE(representation_id, seat_id)` pour ces places et oblige à maintenir deux logiques de vente différentes.

**Options.**
1. Rendre `seat_id` nullable sur `ticket` pour les zones debout et gérer un compteur de capacité séparé.
2. Permettre à l'organisateur de définir par zone si elle est assise ou sans siège via un booléen `is_seated`, tout en conservant des lignes `seat` numérotées en base pour chaque place de la zone.

**Choix.** Option 2.

**Conséquence.** En base de données, `seat_id` est toujours `NOT NULL` et chaque billet pointe vers un `seat` unique. Le moteur de réservation et la contrainte d'unicité fonctionnent de façon identique pour toutes les zones. Lors de l'affichage ou de l'impression du billet, le numéro de siège est masqué si `is_seated` vaut `false`.

---

## 2026-11-10 - D4 : Blocage temporaire (15 min), concurrence et paiement non abouti

**Contexte.** Deux clients peuvent cliquer sur la même place au même instant, et un client qui entame un paiement ne doit pas bloquer une place indéfiniment s'il abandonne. En MySQL 8, contrairement à PostgreSQL, les index uniques partiels (`CREATE UNIQUE INDEX ... WHERE status IN ('RESERVED', 'PAID')`) n'existent pas. Stocker des réservations expirées dans `ticket` maintient toutes les lignes dans l'arbre B-Tree de l'index `UNIQUE(representation_id, seat_id)`. Cela bloque les insertions suivantes ou impose des suppressions et mises à jour fréquentes en base pour des paniers non payés.

**Options.**
1. Tout stocker dans MySQL avec `SELECT ... FOR UPDATE` sur `seat` et recycler les lignes expirées par `UPDATE` sur place.
2. Gérer le blocage temporaire de 15 minutes et l'idempotence dans Redis (`SET hold:rep:{id}:seat:{id} {buyerId} NX EX 900`), et n'insérer dans MySQL que lorsque le paiement aboutit.

**Choix.** Option 2 (Redis pour le verrou temporaire de 15 minutes et l'idempotence, MySQL pour les billets payés).

**Conséquence.**
- Le moteur mono-threadé de Redis exécute `SET ... NX EX 900` de façon atomique en mémoire. Si deux clients cliquent simultanément, le premier obtient le verrou pour 900 secondes et le second reçoit un refus immédiat.
- Si le paiement n'aboutit pas dans les 15 minutes, Redis supprime automatiquement la clé à l'expiration du TTL sans écriture ni réorganisation d'index dans MySQL.
- La table `ticket` dans MySQL ne contient que des billets effectivement payés (`PAID`) ou remboursés (`REFUNDED`), protégés en dernier ressort par une contrainte `UNIQUE(representation_id, seat_id)`.
- Pour afficher le plan de salle, l'application combine les `seat_id` vendus dans MySQL et les verrous actifs dans Redis.

---

## 2026-11-10 - D5 : Annulation d'une représentation

**Contexte.** Un organisateur peut annuler une représentation pour laquelle des billets ont déjà été payés et des places sont en cours de réservation temporaire.

**Options.**
1. Supprimer les billets vendus pour libérer les places.
2. Passer la représentation au statut `CANCELLED`, basculer tous les billets `PAID` au statut `REFUNDED` pour conserver la trace comptable, et supprimer les verrous temporaires Redis de cette représentation.

**Choix.** Option 2.

**Conséquence.** Aucune réservation ni aucun paiement n'est accepté si la représentation n'est pas active (`SCHEDULED`). L'historique des ventes reste intact en base avec le statut `REFUNDED`.
