# Note de session : 2026-11-11 (Niveau 2 : Règles métier, API & Tests)

## 1. Ce qui a été demandé

- Implémenter l'ensemble des règles métier du Niveau 2 (anti-double-vente, verrous temporaires 15 minutes, coupons, annulation/remboursements).
- Construire les contrôleurs d'API REST nécessaires pour alimenter le futur plan de salle 3D Three.js et le parcours d'achat.
- Développer une suite de tests automatisés exhaustive sous PHPUnit.
- Respecter une méthodologie Git professionnelle par commits atomiques sur une branche dédiée (`feature/level-2-ticketing-and-api`).

---

## 2. Ce que l'humain a piloté, corrigé et arbitré face à l'IA

1. **Choix délibéré de Redis pour les verrous temporaires :**
   - *Proposition de l'IA :* L'IA a hésité et proposé une alternative 100% MySQL avec table `seat_holds` pour minimiser le nombre de conteneurs.
   - *Arbitrage humain :* L'utilisateur a confirmé la supériorité architecturale de Redis pour un cas de billetterie : atomicité native mono-threadée (`SET NX EX 900`), expiration automatique en RAM sans encombrer InnoDB avec des lignes zombies.
2. **Exigence d'une stratégie de branches et de commits atomiques :**
   - *Proposition de l'IA :* L'IA envisageait de travailler directement sur `main`.
   - *Recadrage humain :* L'utilisateur a exigé la création d'une branche de fonctionnalité (`feature/level-2-ticketing-and-api`), avec des commits incrémentaux par blocs de travail pour documenter une démarche Git professionnelle (Pull Request et merge).
3. **Détection du bug Doctrine dans `wrapInTransaction` :**
   - *Erreur IA :* Dans `TicketingService::purchase()`, l'IA avait enveloppé la création des entités `Ticket` dans `$em->wrapInTransaction(...)` mais avait omis d'appeler `$this->em->flush()` avant la sortie de la closure. Les tickets restaient en attente dans le UnitOfWork, provoquant des faux conflits sur les tests suivants.
   - *Correction :* Ajout explicite de `$this->em->flush()` dans la transaction pour matérialiser les enregistrements MySQL immédiatement.
4. **Idempotence des tests et gestion de l'isolation :**
   - *Problème :* Lors de l'enchaînement des suites de tests PHPUnit, les entités insérées par les premiers tests modifiaient l'état des événements de démonstration.
   - *Solution :* Encapsulation des tests de service dans des transactions annulées en `tearDown()` (`rollBack()`) et purge sélective des billets de test pour garantir une exécution 100% reproductible à l'infini.

---

## 3. Livrables et résultats techniques

- **Services métier :**
  - `SeatHoldService` : réservations temporaires 15 min dans Redis, anti-scalping (max 6 places par client), libération proactive.
  - `TicketingService` : achat atomique transactionnel MySQL avec verrouillage pessimiste sur coupons (`FOR UPDATE`), annulation d'événement avec remboursement.
- **Contrôleurs API REST (14 routes) :**
  - `AuthController` : `/api/auth/login`, `/api/auth/quick-login`, `/api/auth/me`, `/api/auth/logout`.
  - `EventApiController` : `/api/events`, `/api/events/{id}`, `/api/events/{id}/seats` (fournit la topologie 3D des 400 places pour Three.js).
  - `HoldApiController` : `/api/events/{id}/hold`, `/api/events/{id}/hold/{seatId}`, `/api/cart`.
  - `CheckoutApiController` : `/api/coupons/validate`, `/api/checkout`.
  - `AdminApiController` : `/api/admin/events/{id}/cancel`, `/api/admin/dashboard`.
- **Suite de tests PHPUnit :**
  - **16 tests, 81 assertions, 100% au vert**.
  - Couverture complète : verrous 15 min, concurrence anti-double-vente MySQL, coupons, annulation, et parcours d'API de bout en bout.
