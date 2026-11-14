# Journal de Session IA — 12 Novembre 2026

## 1. Demande de l'utilisateur
- Refonte visuelle intégrale du front-end d'après le système de design **Ravensgate Opera** (`https://inspomcp.dev/examples/ravensgate-opera`).
- Élimination radicale du « slop IA » : suppression des badges techniques ostentatoires, des emojis, et déport des raccourcis d'évaluation vers la page `/login`.
- Intégration de visuels d'opéra de prestige (auditorium royal et écorché architectural de la salle de 400 places).
- Installation de la compétence spécialisée `3d-website-architect` et reconstruction du plan de salle 3D interactif Three.js.
- Correction du bug de création de coupon dans le tableau de bord Direction (`/admin`).

---

## 2. Erreurs de l'IA & Pièges rencontrés

### A. Occlusion géométrique majeure en Three.js (`CylinderGeometry`)
- **Ce que l'IA a fait** : Lors de la modélisation des gradins et du balcon en fer à cheval, le sous-agent Three.js a utilisé `new THREE.CylinderGeometry(outerR, innerR, ...)` en supposant que Three.js produisait un anneau creux avec rayon intérieur et extérieur.
- **Pourquoi c'était faux** : En Three.js, `CylinderGeometry(radiusTop, radiusBottom)` crée un tronc de cône plein allant du centre (`radius = 0`) jusqu'au bord. Cela a créé un gigantesque coin de bois massif plein de $z=-9$ vers l'avant, ensevelissant la moitié de l'orchestre et masquant les fauteuils !
- **Comment cela a été détecté** : Capture d'écran utilisateur montrant une énorme dalle marron barrant l'écran.
- **Correction** : Remplacement par une forme 2D annulaire extrudée (`THREE.Shape.absarc` + `THREE.ExtrudeGeometry`) strictement confinée entre $r=31.5$ et $r=38.5$, laissant le centre du théâtre (rayon 0 à 31) 100% dégagé.

### B. Fragilité du composant « Spotlight » vis-à-vis du domaine
- **Ce que l'IA a fait** : L'IA a initialement codé en dur un spectacle spécifique (« Soirée Gnawa Fusion ») dans une section éditoriale avec un visuel abstrait SVG.
- **Pourquoi c'était faux** : Si ce spectacle venait à être annulé en base, la page d'accueil continuait à promouvoir un événement mort avec un bouton cassé. De plus, la sphère rouge SVG détonnait avec les photographies d'art.
- **Comment cela a été détecté** : Remarque perçante de l'utilisateur : *« if the event get cancelled we will have problems »*.
- **Correction** : Remplacement par une section intemporelle consacrée à l'architecture acoustique de la salle de 400 places (fondée sur l'écorché architectural 3D), 100% découplée de l'état d'un spectacle individuel.

### C. `TypeError` PHP 8.2 sur la création de coupon (`max_uses`)
- **Ce que l'IA a fait** : Le formulaire de création de coupon affichait le champ « Max utilisations » avec le placeholder « Illimité ». Si laissé vide, le JS envoyait `maxUses: null`. Côté backend, `Coupon::setMaxUses(int $maxUses)` avait un typage strict non-nullable `int`.
- **Pourquoi c'était faux** : PHP 8.2 a levé une exception fatale `TypeError: Argument #1 ($maxUses) must be of type int, null given`, causant une erreur 500.
- **Comment cela a été détecté** : Signalement utilisateur : *« fix the coupon creating, because now it doesn't work »*.
- **Correction** : Assouplissement du setter en `?int` avec valeur par défaut à `1000` (pour un usage illimité standard), et assainissement du payload dans le contrôleur d'API et le formulaire JS.

---

## 3. Résultats & État à la fin de session
- **Branche active** : `feature/level-3-frontend-3d`.
- **Tests automatisés** : 21 tests PHPUnit / 125 assertions au vert (100%).
- **Toutes les routes testées** : `/`, `/events/1`, `/checkout`, `/tickets/my`, `/login`, `/admin`, `/cashier` retournent HTTP 200.

