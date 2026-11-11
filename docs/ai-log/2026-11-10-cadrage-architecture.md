# Note de session : 2026-11-10

## 1. Ce qui a été demandé

- Décortiquer les 5 niveaux du challenge et les 5 questions à trancher de l'énoncé.
- Concevoir le modèle de données complet (400 places, configurations de salle, tarification).
- Initialiser le projet Symfony 7.4 + MySQL 8.0 sous Docker sans polluer le système hôte.
- Produire et documenter le diagramme Mermaid dans `docs/MODELE.md` et les décisions dans `docs/DECISIONS.md`.

---

## 2. Ce que l'humain a piloté, corrigé et refusé face à l'IA

1. **Rejet de l'anti-pattern 1NF et invention de la tarification par multiplicateur :**
   - *Erreur IA :* Face à la complexité des prix par zone, l'IA a proposé de créer 5 colonnes de prix directement dans la table des événements (`front_stage_price`, `orchestra_price`, etc.).
   - *Critique et arbitrage humain :* L'utilisateur a refusé d'encoder des noms de zones dans des colonnes et a proposé une solution bien plus élégante : fixer un prix de base unique par événement (`base_price_cents`), et définir un multiplicateur d'augmentation (`price_multiplier_percent`) dans la table `zones`. Modèle 100% 3NF sans redondance.
2. **Clarification stricte des rôles selon le sujet EMC :**
   - *Erreur IA :* L'IA a d'abord imaginé un rôle d'organisateur externe complexe (`ORGANIZER`).
   - *Recadrage humain :* L'utilisateur a relu l'énoncé de l'EMC : la salle gère elle-même sa billetterie pour remplacer son cahier papier. Les 3 rôles doivent être strictement : `CLIENT` (en ligne), `CASHIER` (guichet physique) et `ADMIN` (direction de la salle).
3. **Nettoyage des entités et relations superflues :**
   - Suppression d'une colonne `description` inutile sur `event_types`.
   - Modélisation de la modularité via `event_types` et `configurations` (qui porte `is_seated`) pour commander dynamiquement la vue 3D sans code ad-hoc.
4. **Détection du conteneur PostgreSQL parasite injecté par Symfony Flex :**
   - Lors de l'installation de `symfony/orm-pack`, la recette Flex a injecté un service `database: postgres:16-alpine` dans `docker-compose.yml`. L'IA n'y avait pas prêté attention. L'utilisateur a inspecté ses conteneurs Docker locaux, a identifié cette anomalie et a exigé son retrait pour rester fidèle à MySQL 8.

---

## 3. Fichiers et traces associées

- **Transcription intégrale de la session :**
  - Format brut : `docs/ai-log/transcripts/2026-11-10-session-1.jsonl`
  - Format lisible : `docs/ai-log/transcripts/2026-11-10-session-1.md`
- **Modèle validé :** [docs/MODELE.md](file:///home/houcinee/Desktop/projects/emc/docs/MODELE.md)
- **Décisions d'architecture :** [docs/DECISIONS.md](file:///home/houcinee/Desktop/projects/emc/docs/DECISIONS.md)
