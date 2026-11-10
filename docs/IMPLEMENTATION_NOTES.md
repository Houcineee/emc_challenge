# Notes d'implémentation et cas limites

Ce fichier regroupe les détails techniques, algorithmes et cas limites discutés lors de la conception pour ne rien oublier pendant le développement des Niveaux 1, 2 et 3.

---

## 1. Rôles et autorisations

3 rôles uniquement :
- `ROLE_BUYER` (Acheteur) : consulte les représentations, réserve des places, applique un coupon, paie ses billets, consulte son historique.
- `ROLE_ORGANIZER` (Organisateur) : crée/configure ses représentations, choisit si chaque zone est assise ou debout (`is_seated`), fixe le prix de base par zone, crée et gère les coupons de réduction, peut annuler une représentation.
- `ROLE_ADMIN` (Administrateur) : gère l'infrastructure / la salle et les comptes, mais **n'a aucun droit sur les prix, les coupons ou l'émission de billets gratuits**.

---

## 2. Tarification et coupons

- **Prix de base** : défini par l'organisateur sur chaque zone/catégorie de la représentation.
- **Coupons** : créés uniquement par l'organisateur pour remplacer une grille tarifaire complexe :
  - Tarif étudiant / réduit : coupon avec réduction (ex. `-20%` ou `-50 MAD`).
  - Invitation / Gratuit (VIP, presse) : coupon à `-100%` (à usage unique ou limité).
  - Réduction de dernière minute (ex. 1h avant la séance) : remise automatique ou coupon promotionnel.
- **Snapshot du prix sur `Ticket`** :
  - Au moment de l'achat, on enregistre dans la table `ticket` :
    - `base_price` (prix de base au moment de l'achat)
    - `discount_amount` (montant de la réduction appliquée)
    - `final_price` (montant réellement payé)
    - `coupon_id` (nullable, référence au coupon utilisé pour traçabilité)
  - Si l'organisateur modifie le prix de la zone ou le pourcentage du coupon plus tard, les billets déjà vendus ne changent jamais de prix.

---

## 3. Configuration de salle (Assis vs Sans siège / Fosse)

- Chaque zone possède un booléen `is_seated` choisi par l'organisateur :
  - `is_seated = true` : zone avec chaises numérotées (ex. Orchestre, Balcon).
  - `is_seated = false` : zone sans siège / fosse debout / placement libre.
- **Astuce base de données** :
  - Même si `is_seated = false`, toutes les places de la zone existent comme lignes dans la table `seat` avec un numéro interne (`seat_id NOT NULL` partout dans `ticket`).
  - Avantage : zéro colonne `NULL` dans `ticket`, une seule logique de réservation/verrouillage, et la contrainte `UNIQUE(representation_id, seat_id)` fonctionne partout.
  - Côté UI / Impression du billet PDF ou HTML :
    - Si `zone.isSeated() === true` : afficher `Rang X - Siège Y`.
    - Si `zone.isSeated() === false` : masquer le numéro et afficher `Placement libre / Sans siège`.

---

## 4. Architecture de réservation et concurrence

### Option retenue : Redis (blocage 15 min + idempotence) + MySQL (billets payés)

#### A. Blocage temporaire de 15 minutes dans Redis
Clé Redis par place et par représentation :
```text
hold:rep:{representationId}:seat:{seatId} -> "{buyerId}"
```
Commande atomique à la réservation :
```text
SET hold:rep:{repId}:seat:{seatId} "{buyerId}" NX EX 900
```
- `NX` : n'écrit que si la clé n'existe pas (garanti sans course critique car Redis est mono-threadé).
- `EX 900` : expiration automatique au bout de 900 secondes (15 minutes).

#### B. Affichage du plan de salle (Fusion MySQL + Redis)
Pour savoir quelles places sont indisponibles sur une représentation :
1. Récupérer les `seat_id` ayant un billet `PAID` dans MySQL pour cette représentation.
2. Récupérer dans Redis les clés `hold:rep:{repId}:seat:*` actives (via `MGET` sur les IDs des sièges de la salle ou un Set/Hash par représentation).
3. Marquer comme occupées l'union des deux ensembles (sauf si le verrou Redis appartient à l'acheteur connecté lui-même, auquel cas il voit que c'est sa sélection en cours).

#### C. Validation du paiement et écriture dans MySQL
Quand l'acheteur clique sur « Payer » :
1. **Idempotence (Redis)** :
   ```text
   SET idemp:checkout:{idempotencyKey} "PROCESSING" NX EX 300
   ```
   Empêche le double débit si l'utilisateur double-clique sur le bouton Payer.
2. **Vérification de la représentation** :
   Vérifier que `representation.status === 'SCHEDULED'` et que `representation.startsAt > now`.
3. **Vérification du verrou Redis** :
   Lire `GET hold:rep:{repId}:seat:{seatId}` :
   - Si `null` : les 15 minutes ont expiré -> refuser le paiement.
   - Si la valeur `!== (string) $currentUser->getId()` : un autre acheteur a récupéré la place après expiration -> refuser le paiement.
4. **Transaction MySQL** :
   - Insérer le `Ticket` avec `status = 'PAID'` et le prix figé (`final_price`).
   - La table `ticket` possède la contrainte `UNIQUE(representation_id, seat_id)` en filet de sécurité absolu.
   - Si un coupon limité est utilisé, incrémenter son compteur d'utilisation atomiquement.
5. **Nettoyage Redis** :
   Supprimer le verrou temporaire `DEL hold:rep:{repId}:seat:{seatId}` après le `COMMIT` MySQL.

---

### Rappel de l'alternative 100 % MySQL (si besoin de fallback ou pour les tests sans Redis)
Si on fait aussi le verrouillage en MySQL pur :
1. Toujours verrouiller la table `seat` (qui existe toujours) et non `ticket` (qui peut avoir 0 ligne au début) :
   ```sql
   SELECT * FROM seat WHERE id IN (:seatIds) ORDER BY id ASC FOR UPDATE;
   ```
   *(Toujours trier par `id ASC` pour rendre les deadlocks mathématiquement impossibles lors de la réservation de plusieurs sièges).*
2. Le `SELECT ... FOR UPDATE` ne dure que ~5 ms (le temps de la requête HTTP) et est relâché dès le `COMMIT`.
3. Pour éviter le bug de l'ordre du `flush()` Doctrine (`INSERT` exécuté avant `DELETE` dans le UnitOfWork) et éviter de réorganiser l'arbre B-Tree de l'index `UNIQUE(representation_id, seat_id)` :
   - Si `ticket === null` -> `INSERT` (`status = 'RESERVED'`, `reserved_at = now`).
   - Si `ticket` est `PAID` ou (`RESERVED` et non expiré) -> lever `SeatAlreadyTakenException`.
   - Si `ticket` est `RESERVED` et expiré (`reserved_at + 15 min <= now`) -> faire un **`UPDATE` sur place** en écrasant `buyer` et `reserved_at` d'un seul coup.

---

## 5. Liste des cas limites (Edge Cases) à couvrir dans `tests/`

1. **Double clic / Retour arrière du même acheteur pendant ses 15 minutes** :
   - Si Alice a déjà le verrou Redis `hold:rep:1:seat:5` (avec sa propre valeur `alice_id`) et reclique sur le siège #5 ou fait « Précédent », ne pas lui renvoyer une erreur « Place prise par quelqu'un d'autre » : la rediriger vers son panier/paiement en cours sans réinitialiser indéfiniment son TTL de 15 minutes.
2. **Paiement tardif à la 15e minute (« Paiement fantôme »)** :
   - Alice ouvre la page de paiement à 14h00. Son verrou expire à 14h15.
   - Bob réserve la place à 14h15m02s.
   - Alice clique sur « Payer » à 14h15m05s -> Le système vérifie que le détenteur du verrou est bien Alice avant d'encaisser. Comme c'est maintenant Bob (ou expiré), le paiement d'Alice est rejeté.
3. **Anti-scalping / Blocage abusif de toute la salle** :
   - Limiter le nombre de places qu'un même acheteur peut bloquer simultanément en attente de paiement (ex. max 6 places en hold par représentation et par acheteur).
4. **Validation d'appartenance du siège à la représentation** :
   - Lors de la réservation de `(representationId, seatId)`, vérifier que `seat.zone` appartient bien à la configuration/salle de `representation`. Empêche un utilisateur de forger une requête HTTP avec un `seatId` d'une autre salle ou d'une zone fermée pour cette représentation.
5. **Réservation ou paiement sur une représentation annulée ou passée** :
   - Vérifier systématiquement `representation.status === 'SCHEDULED'` et `representation.startsAt > now` à la fois au moment du hold ET au moment du paiement.
6. **Annulation d'une représentation par l'organisateur** :
   - Passe `representation.status` à `'CANCELLED'`.
   - Passe tous les billets `PAID` de cette représentation à `'REFUNDED'` (sans jamais les supprimer en base).
   - Supprime tous les verrous temporaires Redis `hold:rep:{repId}:*`.
   - Si un coupon à usage limité avait été consommé sur un billet remboursé, décrémenter `used_count` du coupon.
